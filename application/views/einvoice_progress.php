<?php
defined('BASEPATH') OR exit('No direct script access allowed');
$safe_invoice = htmlspecialchars($invoice, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Invoice processing</title>
	<style>
		:root { color-scheme: light; font-family: Arial, sans-serif; color: #172b4d; }
		body { margin: 0; background: #f4f6f8; }
		main { box-sizing: border-box; max-width: 620px; margin: 8vh auto; padding: 32px; background: #fff; border: 1px solid #dfe3e8; border-radius: 8px; box-shadow: 0 4px 18px rgba(23, 43, 77, .08); }
		h1 { margin: 0 0 8px; font-size: 24px; }
		.invoice { margin: 0 0 28px; color: #52606d; overflow-wrap: anywhere; }
		.step { display: flex; align-items: center; gap: 12px; padding: 14px 0; border-top: 1px solid #edf0f2; }
		.icon { width: 22px; height: 22px; display: inline-flex; align-items: center; justify-content: center; flex: 0 0 22px; border-radius: 50%; background: #e9eef5; color: #52606d; font-weight: bold; }
		.pending .icon { border: 2px solid #c9d2df; border-top-color: #1264a3; background: transparent; animation: spin .8s linear infinite; }
		.success .icon { background: #e4f5eb; color: #16794b; }
		.error .icon { background: #fde9e7; color: #b42318; }
		.step-copy { min-width: 0; }
		.step-title { font-weight: bold; }
		.step-detail { margin-top: 4px; color: #52606d; overflow-wrap: anywhere; white-space: pre-wrap; }
		#message { margin-top: 18px; overflow-wrap: anywhere; }
		@keyframes spin { to { transform: rotate(360deg); } }
	</style>
</head>
<body>
<main>
	<h1>Invoice processing</h1>
	<p class="invoice">Invoice: <strong><?php echo $safe_invoice; ?></strong></p>
	<div id="einvoice" class="step pending">
		<span class="icon" aria-hidden="true"></span>
		<div class="step-copy">
			<div class="step-title">e-Invoice</div>
			<div class="step-detail">Reading invoice and contacting NIC...</div>
		</div>
	</div>
	<div id="ewaybill" class="step">
		<span class="icon" aria-hidden="true">-</span>
		<div class="step-copy">
			<div class="step-title">e-Way Bill</div>
			<div class="step-detail">Not generated: this feature is currently disabled.</div>
		</div>
	</div>
	<div id="message" role="status" aria-live="polite"></div>
</main>
<script>
(function () {
	'use strict';
	var invoiceStep = document.getElementById('einvoice');
	var invoiceDetail = invoiceStep.querySelector('.step-detail');
	var icon = invoiceStep.querySelector('.icon');
	var message = document.getElementById('message');
	var requestUrl = window.location.pathname + window.location.search;
	requestUrl += requestUrl.indexOf('?') === -1 ? '?format=json' : '&format=json';

	fetch(requestUrl, { headers: { 'Accept': 'application/json' } })
		.then(function (response) {
			return response.json().then(function (data) {
				return { ok: response.ok, data: data };
			});
		})
		.then(function (result) {
			if (!result.ok || !result.data.success) {
				throw new Error(result.data.error || 'Invoice processing failed.');
			}
			var response = result.data.response || {};
			invoiceStep.className = 'step success';
			icon.textContent = '\u2713';
			invoiceDetail.textContent = 'Generated successfully'
				+ (response.Irn ? '\nIRN: ' + response.Irn : '')
				+ (response.AckNo ? '\nAcknowledgement: ' + response.AckNo : '')
				+ (response.AckDt ? '\nAcknowledged: ' + response.AckDt : '');
			message.textContent = 'e-Invoice completed successfully.';
		})
		.catch(function (error) {
			invoiceStep.className = 'step error';
			icon.textContent = '\u2717';
			invoiceDetail.textContent = error.message;
			message.textContent = 'The invoice could not be completed. See the error above.';
		});
}());
</script>
</body>
</html>
