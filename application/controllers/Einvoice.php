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
		$this->output->set_content_type('application/json');
		$segments = func_get_args();
		if (count($segments) > 1) {
			$docid = implode('/', $segments);
		}
		if (!$docid) {
			$docid = $this->input->get('invoice', TRUE);
		}
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


			// E-way bill generation is disabled until transport details are mapped.
			$response = $this->nic_einvoice->generate($invoice, NULL);
			if (isset($response['Status']) && (string) $response['Status'] !== '1') {
				throw new RuntimeException($this->nic_error($response));
			}
			if (isset($response['ewaybill']['Status'])
				&& (string) $response['ewaybill']['Status'] !== '1') {
				throw new RuntimeException($this->nic_error($response['ewaybill']));
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

	private function invoice_payload($data)
	{
		$header = $data['header'][0];
		$seller = $data['export'][0];
		$buyer = $data['buyer'][0];
		$seller_gstin = $this->field($seller, 'GSTIN', $this->config->item('einv_gstin'));
		$buyer_gstin = $this->field($buyer, 'GSTIN', 'URP');
		$buyer_state = $this->state_code($buyer_gstin);
		$country_code = strtoupper(trim((string) $this->field(
			$header, 'COUNTRYFINAL', $this->config->item('einv_export_country_code')
		)));

		print_r($seller); exit;

		if (!$seller_gstin || strlen($seller_gstin) !== 15 || !$buyer_state
			|| !preg_match('/^[A-Z]{2}$/', $country_code)) {
			throw new RuntimeException('Invoice requires seller GSTIN, buyer state code, and two-letter destination country code.');
		}
		$items = array();
		$assessable = 0;
		foreach ($data['grid'] as $index => $row) {
			$amount = (float) $this->field($row, 'AMOUNT', 0);
			$assessable += $amount;
			$items[] = array(
				'SlNo' => (string) ($index + 1),
				'PrdDesc' => $this->field($row, 'DESGOODS', ''),
				'IsServc' => 'N',
				'HsnCd' => $this->field($row, 'HSN', ''),
				'Qty' => (float) $this->field($row, 'INVOICEQTY', 0),
				'Unit' => 'PCS',
				'UnitPrice' => (float) $this->field($row, 'RATE', 0),
				'TotAmt' => $amount,
				'AssAmt' => $amount,
				'TotItemVal' => $amount,
			);
		}
		return array(
			'Version' => '1.1',
			'TranDtls' => array('TaxSch' => 'GST', 'SupTyp' => 'EXP'),
			'DocDtls' => array('Typ' => 'INV', 'No' => $header['INVOICENO'], 'Dt' => $this->date($header['DOCDATE'])),
			'SellerDtls' => $this->party($seller, $seller_gstin),
			'BuyerDtls' => array_merge($this->party($buyer, $buyer_gstin), array('Pos' => $buyer_state)),
			'ExpDtls' => array(
				'RefClm' => FALSE,
				'CntCode' => $country_code,
				'ForCur' => $this->config->item('einv_default_export_currency'),
			),
			'ItemList' => $items,
			'ValDtls' => array('AssVal' => $assessable, 'IgstVal' => 0, 'TotInvVal' => $assessable),
		);
	}

	private function ewaybill_payload($data)
	{
		$header = $data['header'][0];
		$seller = $data['export'][0];
		$buyer = $data['buyer'][0];
		$shipto = isset($data['shipto'][0]) ? $data['shipto'][0] : $buyer;
		return array(
			'Irn' => '',
			'Distance' => 0,
			'TransMode' => '1',
			'ExpShipDtls' => $this->eway_address($shipto),
			'DispDtls' => $this->eway_address($seller),
		);
	}

	private function eway_address($row)
	{
		return array(
			'Addr1' => $this->field($row, 'ADD1', ''),
			'Addr2' => $this->field($row, 'ADD2', ''),
			'Loc' => $this->field($row, 'CITYNAME', ''),
			'Pin' => (int) $this->field($row, 'PINCODE', $this->field($row, 'PIN', 0)),
			'Stcd' => substr((string) $this->field($row, 'GSTIN', ''), 0, 2),
		);
	}

	private function state_code($gstin)
	{
		if ($gstin === 'URP') {
			return '96';
		}
		return preg_match('/^[0-9]{2}[A-Z0-9]{13}$/', (string) $gstin)
			? substr($gstin, 0, 2) : '';
	}

	private function party($row, $default_gstin)
	{
		return array(
			'Gstin' => $this->field($row, 'GSTIN', $default_gstin),
			'LglNm' => $this->field($row, 'PARTYID', $this->field($row, 'EXPORTNAME', '')),
			'Addr1' => $this->field($row, 'ADD1', ''),
			'Addr2' => $this->field($row, 'ADD2', ''),
			'Loc' => $this->field($row, 'CITYNAME', ''),
			'Pin' => (int) $this->field($row, 'PINCODE', $this->field($row, 'PIN', 0)),
			'Stcd' => $this->state_code($this->field($row, 'GSTIN', 'URP')),
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
