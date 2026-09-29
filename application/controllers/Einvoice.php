<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Einvoice extends CI_Controller
{
	public function __construct()
	{
		parent::__construct();
		$this->load->model('Invoice_model');
		$this->load->library('Nic_einvoice');
		$this->config->load('einvoice');
	}

	/*
	 * The Windows client calls:
	 *   /index.php/einvoice/create/CRG/DOI/26-27/000010
	 * No POST body is required.
	 */
	public function create($docid = NULL)
	{
		$segments = func_get_args();
		if (count($segments) > 1) {
			$docid = implode('/', $segments);
		}
		if (!$docid) {
			$docid = $this->input->get('invoice', TRUE);
		}
		if ($this->wants_progress_page()) {
			$this->output->set_content_type('text/html', 'UTF-8');
			return $this->output->set_output($this->load->view('einvoice_progress', array(
				'invoice' => $docid ? $docid : '',
			), TRUE));
		}
		$this->output->set_content_type('application/json');
		if (!$docid || !preg_match('/^[A-Za-z0-9\/_.-]+$/', $docid)) {
			return $this->json_error('A valid invoice number is required.', 400);
		}
		try {
			$details = $this->Invoice_model->details($docid);

			if (empty($details['header'][0]) || empty($details['grid'])
				|| empty($details['export'][0]) || empty($details['buyer'][0])) {
				throw new RuntimeException('Invoice was not found, lacks parties, or has no line items.');
			}
			
			$invoice = $this->invoice_payload($details);
			$response = $this->nic_einvoice->generate($invoice);
			if (isset($response['Status']) && (string) $response['Status'] !== '1') {
				throw new RuntimeException($this->nic_error($response));
			}
			$this->Invoice_model->save_response($docid, $response);
			return $this->output->set_status_header(200)->set_output(json_encode(array(
				'success' => TRUE, 'invoice' => $docid, 'response' => $response,
			)));
		} catch (Exception $exception) {
			$this->Invoice_model->save_error($docid, $exception->getMessage());
			return $this->json_error($exception->getMessage(), 502);
		}
	}

	private function wants_progress_page()
	{
		$format = strtolower((string) $this->input->get('format', TRUE));
		if ($format === 'json') {
			return FALSE;
		}
		if ($format === 'html') {
			return TRUE;
		}
		$accept = strtolower((string) $this->input->server('HTTP_ACCEPT'));
		return strpos($accept, 'text/html') !== FALSE && strpos($accept, 'application/json') === FALSE;
	}

	private function invoice_payload($data)
	{
		$header = $data['header'][0];
		$seller = $data['export'][0];
		$buyer = $data['buyer'][0];
		$footer = isset($data['footer'][0]) ? $data['footer'][0] : array();
		$seller_gstin = $this->field($seller, 'GSTIN', $this->config->item('einv_gstin'));

		$seller_state = $this->state_code($seller_gstin);
		$is_export = strtoupper(trim((string) $this->field($header, 'TYPE', ''))) === 'EXPORT';
		$buyer_gstin = $this->field($buyer, 'GSTIN', $is_export ? 'URP' : '');
		$buyer_state = $is_export ? '96' : $this->state_code($buyer_gstin);
		if (!$seller_state || (!$is_export && !$buyer_state)) {
			throw new RuntimeException('Invoice requires valid seller and buyer GSTIN state codes.');
		}
		$country_code = '';
		if ($is_export) {
			$country_code = strtoupper(trim((string) $this->field($header, 'COUNTRYFINAL', '')));
			if (!preg_match('/^[A-Z]{2}$/', $country_code)) {
				$country_code = strtoupper(trim((string) $this->config->item('einv_export_country_code')));
			}
			if (!preg_match('/^[A-Z]{2}$/', $country_code)) {
				throw new RuntimeException('Export invoice requires a two-letter destination country code.');
			}
		}
		$items = array();
		$item_fields = array(
			'SlNo', 'PrdDesc', 'IsServc', 'HsnCd', 'Qty', 'Unit', 'UnitPrice',
			'TotAmt', 'Discount', 'AssAmt', 'GstRt', 'IgstAmt', 'CgstAmt',
			'SgstAmt', 'CesRt', 'CesAmt', 'TotItemVal',
		);
		foreach ($data['grid'] as $row) {
			$item = $this->nic_fields($row, $item_fields);
			$this->require_fields($item, $item_fields, 'ItemList');
			$items[] = $item;
		}
		$value_fields = array('AssVal', 'CgstVal', 'SgstVal', 'IgstVal', 'CesVal', 'Discount', 'TotInvVal');
		$value_details = $this->nic_fields($footer, $value_fields);
		$this->require_fields($value_details, $value_fields, 'ValDtls');
		$payload = array(
			'Version' => '1.1',
			'TranDtls' => array(
				'TaxSch' => 'GST',
				'SupTyp' => $is_export ? ($this->field($footer, 'LUTNO', '') ? 'EXPWOP' : 'EXPWP') : 'B2B',
				'RegRev' => 'N',
				'EcmGstin' => NULL,
				'IgstOnIntra' => 'N',
			),
			'DocDtls' => array('Typ' => 'INV', 'No' => $header['INVOICENO'], 'Dt' => $this->date($header['DOCDATE'])),
			'SellerDtls' => $this->party($seller, $seller_gstin, $seller_state),
			'BuyerDtls' => array_merge($this->party($buyer, $buyer_gstin), array('Pos' => $buyer_state)),
			'ItemList' => $items,
			'ValDtls' => $value_details,
		);
		if ($is_export) {
			$payload['ExpDtls'] = array(
				'RefClm' => FALSE,
				'CntCode' => $country_code,
				'ForCur' => $this->config->item('einv_default_export_currency'),
			);
		}
		return $payload;
	}

	private function nic_fields($row, $allowed_fields)
	{
		$values = array();
		foreach ($row as $key => $value) {
			$normalized_key = strtoupper((string) $key);
			foreach ($allowed_fields as $field) {
				if ($normalized_key === strtoupper($field)) {
					$values[$field] = $value;
					break;
				}
			}
		}
		return $values;
	}

	private function require_fields($values, $required_fields, $section)
	{
		$missing = array();
		foreach ($required_fields as $field) {
			if (!array_key_exists($field, $values)) {
				$missing[] = $field;
			}
		}
		if ($missing) {
			throw new RuntimeException($section . ' query is missing NIC fields: ' . implode(', ', $missing) . '.');
		}
	}

	private function state_code($gstin)
	{
		if ($gstin === 'URP') {
			return '96';
		}
		return preg_match('/^[0-9]{2}[A-Z0-9]{13}$/', (string) $gstin)
			? substr($gstin, 0, 2) : '';
	}

	private function party($row, $default_gstin, $default_state = NULL)
	{
		$gstin = $this->field($row, 'GSTIN', $default_gstin);
		return array(
			'Gstin' => $gstin,
			'LglNm' => $this->field($row, 'PARTYID', $this->field($row, 'EXPORTNAME', '')),
			'Addr1' => $this->field($row, 'ADD1', ''),
			'Addr2' => $this->field($row, 'ADD2', ''),
			'Loc' => $this->field($row, 'CITYNAME', ''),
			'Pin' => (int) $this->field($row, 'PINCODE', $this->field($row, 'PIN', 0)),
			'Stcd' => $default_state ? $default_state : $this->state_code($gstin),
		);
	}

	private function field($row, $name, $default)
	{
		return isset($row[$name]) && $row[$name] !== NULL ? $row[$name] : $default;
	}

	private function date($value)
	{
		$time = strtotime($value);
		return $time ? date('d/m/Y', $time) : $value;
	}

	private function json_error($message, $status)
	{
		return $this->output->set_status_header($status)->set_output(json_encode(array(
			'success' => FALSE, 'error' => $message,
		)));
	}

	private function nic_error($response)
	{
		if (!empty($response['ErrorDetails'])) {
			return json_encode($response['ErrorDetails']);
		}
		return 'NIC rejected the request.';
	}
}
