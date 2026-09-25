<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Invoice_model extends CI_Model
{
	public function __construct()
	{
		parent::__construct();
		$this->config->load('invoice_queries');
	}

	public function details($docid)
	{
		$queries = $this->config->item('invoice_queries');
		$details = array();
		foreach ($queries as $name => $sql) {
			$query = $this->db->query($sql, array($docid));
			if ($query === FALSE) {
				throw new RuntimeException('Unable to read invoice ' . $docid . ' (' . $name . ').');
			}
			$details[$name] = $query->result_array();
		}
		return $details;
	}

	public function save_response($docid, $response)
	{
		$data = array(
			'einv_irn' => $this->value($response, 'Irn'),
			'einv_ack_no' => $this->value($response, 'AckNo'),
			'einv_ack_date' => $this->oracle_date($this->value($response, 'AckDt')),
			'einv_qr_code' => $this->value($response, 'SignedQRCode'),
			'eway_bill_no' => $this->value($response, 'EwbNo'),
			'eway_bill_date' => $this->oracle_date($this->value($response, 'EwbDt')),
			'eway_valid_upto' => $this->oracle_date($this->value($response, 'EwbValidTill')),
			'einv_status' => 'SUCCESS',
			'einv_response' => json_encode($response),
			'einv_updated_at' => date('Y-m-d H:i:s'),
		);
		$this->db->where('docid', $docid);
		if (!$this->db->update('docinvmas', $data)) {
			throw new RuntimeException('Unable to save e-invoice response for ' . $docid . '.');
		}
	}

	public function save_error($docid, $message)
	{
		$this->db->where('docid', $docid);
		$this->db->update('docinvmas', array(
			'einv_status' => 'FAILED',
			'einv_response' => json_encode(array('error' => $message)),
			'einv_updated_at' => date('Y-m-d H:i:s'),
		));
	}

	private function value($data, $key)
	{
		return isset($data[$key]) ? $data[$key] : NULL;
	}

	private function oracle_date($value)
	{
		if (!$value) {
			return NULL;
		}
		$timestamp = strtotime($value);
		return $timestamp ? date('Y-m-d H:i:s', $timestamp) : NULL;
	}
}
