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
			$ewaybill = $this->ewaybill_payload($details);
			$response = $this->nic_einvoice->generate($invoice, $ewaybill);
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
			);
		}
		return array(
			'Version' => '1.1',
			'TranDtls' => array('TaxSch' => 'GST', 'SupTyp' => 'EXP'),
			'DocDtls' => array('Typ' => 'INV', 'No' => $header['INVOICENO'], 'Dt' => $this->date($header['DOCDATE'])),
			'SellerDtls' => $this->party($seller, $this->config->item('einv_gstin')),
			'BuyerDtls' => $this->party($buyer, 'URP'),
			'ItemList' => $items,
			'ValDtls' => array('AssVal' => $assessable, 'TotInvVal' => $assessable),
		);
	}

	private function ewaybill_payload($data)
	{
		$header = $data['header'][0];
		$seller = $data['export'][0];
		$buyer = $data['buyer'][0];
		$footer = isset($data['footer'][0]) ? $data['footer'][0] : array();
		$items = array();
		foreach ($data['grid'] as $row) {
			$items[] = array(
				'productName' => $this->field($row, 'DESGOODS', ''),
				'productDesc' => $this->field($row, 'DESGOODS', ''),
				'hsnCode' => (int) $this->field($row, 'HSN', 0),
				'quantity' => (float) $this->field($row, 'INVOICEQTY', 0),
				'taxableAmount' => (float) $this->field($row, 'AMOUNT', 0),
			);
		}
		return array(
			'SupplyType' => 'O',
			'SubSupplyType' => 1,
			'DocType' => 'INV',
			'DocNo' => $header['INVOICENO'],
			'DocDate' => $this->date($header['DOCDATE']),
			'FromGstin' => $this->field($seller, 'GSTIN', $this->config->item('einv_gstin')),
			'FromTrdName' => $this->field($seller, 'EXPORTNAME', ''),
			'FromAddr1' => $this->field($seller, 'ADD1', ''),
			'FromPlace' => $this->field($seller, 'CITYNAME', ''),
			'FromPincode' => (int) $this->field($seller, 'PIN', 0),
			'ToGstin' => $this->field($buyer, 'GSTIN', 'URP'),
			'ToTrdName' => $this->field($buyer, 'PARTYID', ''),
			'ToAddr1' => $this->field($buyer, 'ADD1', ''),
			'ToPlace' => $this->field($buyer, 'CITYNAME', ''),
			'ToPincode' => (int) $this->field($buyer, 'PINCODE', 0),
			'TransMode' => 1,
			'TotalValue' => (float) $this->field($footer, 'TOTALGROSSWGT', 0),
			'TotalInvoiceValue' => (float) $this->field($footer, 'TOTALGROSSWGT', 0),
			'ItemList' => $items,
		);
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
