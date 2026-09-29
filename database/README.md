# e-Invoice setup

1. Copy `application/config/einvoice_local.php.example` to
   `application/config/einvoice_local.php` and fill in the NIC/GSP sandbox or
   production credentials. AppKey is generated automatically as a fresh
   random 32-byte value for each authentication request. The supplied public key is already at
   `application/config/keys/einv_public_key.pem`.
   Set `einv_export_country_code` to the destination's two-letter ISO country code
   if `COUNTRYFINAL` from Oracle is a country name rather than a code.
2. Run `database/einvoice_oracle.sql` once as the `CRERP` schema owner.
3. From the Windows application, call
   `index.php/einvoice/create/CRG/DOI/26-27/000010`. The complete path is
   treated as the invoice number; slashes are not discarded. A query-string
   call is also supported: `index.php/einvoice/create?invoice=CRG%2FDOI%2F26-27%2F000010`.

Browser requests display a progress page followed by the e-invoice success or
error result. Programmatic callers can request JSON using
`?format=json`; the endpoint does not need a POST body. Successful responses
write the IRN, acknowledgement, signed QR code, status, and full response into
`DOCINVMAS`. Credentials are intentionally not included in the repository.

The PEM key must match the selected API environment. Do not use a sandbox key
with a production endpoint, or a production key with a sandbox endpoint.

E-way bill generation is not part of the current endpoint. Add it separately
after the required transport fields are mapped from Oracle.

Invoice classification uses `DOCINVMAS.TYPE`: `EXPORT` creates an export
invoice; other values are domestic. Domestic invoices compare the first two
GSTIN characters to choose intra-state (CGST/SGST) or inter-state (IGST).
The `grid` query must return the NIC `ItemList` fields (`SlNo`, `PrdDesc`,
`IsServc`, `HsnCd`, `Qty`, `Unit`, `UnitPrice`, `TotAmt`, `Discount`, `AssAmt`,
`GstRt`, `IgstAmt`, `CgstAmt`, `SgstAmt`, `CesRt`, `CesAmt`, `TotItemVal`).
The `footer` query must return `AssVal`, `CgstVal`, `SgstVal`, `IgstVal`,
`CesVal`, `Discount`, and `TotInvVal` for `ValDtls`, plus `LUTNO` to choose
the export supply type. Field aliases are matched case-insensitively and
converted to NIC's required JSON field casing.
