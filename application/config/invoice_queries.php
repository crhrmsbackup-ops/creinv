<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
 * These queries intentionally use ? placeholders. CI binds them safely before
 * handing the statement to Oracle.
 * Keep the aliases stable because Invoice_model maps them to NIC's payload.
 */
$config['invoice_queries'] = array(
	'export' => "SELECT a.companyid exportname, a.add1, a.add2, a.add3, a.pin, b.cityname, a.gstno GSTIN
		FROM exportmas a, citymast b, docinvmas c
		WHERE a.add4 = b.citymastid AND c.expname = a.exportmasid AND c.docid = ?",
	'consign' => "SELECT a.partyid, a.add1, a.add2, a.add3, a.pincode, b.cityname
		FROM consignmas a, citymast b, docinvmas c
		WHERE a.city = b.citymastid(+) AND c.consignee = a.consignmasid AND c.docid = ?",
	'shipto' => "SELECT a.partyid, a.add1, a.add2, a.add3, a.pincode, b.cityname
		FROM invbuymas a, citymast b, docinvmas c
		WHERE a.city = b.citymastid(+) AND c.shiptto = a.invbuymasid AND c.docid = ?",
	'buyer' => "SELECT a.partyid, a.add1, a.add2, a.add3, a.pincode, b.cityname
		FROM invbuymas a, citymast b, docinvmas c
		WHERE a.city = b.citymastid(+) AND c.buycon = a.invbuymasid AND c.docid = ?",
	'header' => "SELECT a.docid invoiceno, a.docdate, b.ieno iecode, a.pono, a.buypo buyerpono,
		c.season, a.otherref, a.precar, d.cityname placeofreceipt, a.vessel, e.port portofloading,
		f.port portofdischarge, g.countryname countryoforigin, h.countryname countryfinal, i.termname
		FROM docinvmas a, exportmas b, seasonmas c, citymast d, invportmas e, invportmas f,
		countrymast g, countrymast h, delpaymas i
		WHERE a.iecode = b.exportmasid AND a.season = c.seasonmasid AND a.placerec = d.citymastid
		AND a.portload = e.invportmasid AND a.portdis = f.invportmasid
		AND a.countryorgin = g.countrymastid AND a.counfin = h.countrymastid
		AND a.termdel = i.delpaymasid AND a.docid = ?",
	'grid' => "SELECT b.desgoods, b.po pono, b.hsn, c.pono styleno, SUM(b.invqty) invoiceqty,
		MAX(b.rate) rate, SUM(b.amount) amount
		FROM docinvmas a, docinvdet b, ocnmast c
		WHERE a.docinvmasid = b.docinvmasid AND b.packsty = c.ocnmastid
		AND a.docid = ?
		GROUP BY b.desgoods, b.po, b.hsn, c.pono
		ORDER BY c.pono, b.desgoods",
	'footer' => "SELECT a.totnetwt totalnetwgt, a.totgrswt totalgrosswgt, b.companyid,
		b.add1, b.add2, b.add3, b.pin, c.cityname, b.rexregno, a.lutno, a.arnno, a.dt,
		d.bankname, d.address, d.acno, d.branchcode, d.swiftcode
		FROM docinvmas a, exportmas b, citymast c, branchmas d
		WHERE a.storgin = b.exportmasid AND b.add4 = c.citymastid
		AND a.bankdet = d.branchmasid 		AND a.docid = ?",
);
