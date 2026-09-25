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
		$sql = 'UPDATE DOCINVMAS SET
			EINV_IRN = ?, EINV_ACK_NO = ?, EINV_ACK_DATE = ?, EINV_QR_CODE = ?,
			EWAY_BILL_NO = ?, EWAY_BILL_DATE = ?, EWAY_VALID_UPTO = ?,
			EINV_STATUS = ?, EINV_RESPONSE = ?, EINV_UPDATED_AT = ?
			WHERE DOCID = ?';
		$binds = array(
			$this->value($response, 'Irn'),
			$this->value($response, 'AckNo'),
			$this->oracle_date($this->value($response, 'AckDt')),
			$this->value($response, 'SignedQRCode'),
			$this->value($response, 'EwbNo'),
			$this->oracle_date($this->value($response, 'EwbDt')),
			$this->oracle_date($this->value($response, 'EwbValidTill')),
			'SUCCESS',
			json_encode($response),
			date('Y-m-d H:i:s'),
			$docid,
		);
		if (!$this->db->query($sql, $binds)) {
			throw new RuntimeException('Unable to save e-invoice response for ' . $docid . '.');
		}
	}

	public function save_error($docid, $message)
	{
		$sql = 'UPDATE DOCINVMAS SET EINV_STATUS = ?, EINV_RESPONSE = ?,
			EINV_UPDATED_AT = ? WHERE DOCID = ?';
		$this->db->query($sql, array(
			'FAILED',
			json_encode(array('error' => $message)),
			date('Y-m-d H:i:s'),
			$docid,
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
