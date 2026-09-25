# e-Invoice setup

1. Copy `application/config/einvoice_local.php.example` to
   `application/config/einvoice_local.php` and fill in the NIC/GSP sandbox or
   production credentials. The supplied public key is already at
   `application/config/keys/einv_public_key.pem`.
2. Run `database/einvoice_oracle.sql` once as the `CRERP` schema owner.
3. From the Windows application, call
   `index.php/einvoice/create/{invoice-number}`. A query-string call is also
   supported: `index.php/einvoice/create?invoice={invoice-number}`.

The endpoint accepts only a URL invoice number and does not need a POST body.
It returns JSON and writes the IRN, acknowledgement, signed QR code, e-way
bill values, status, and full response into `DOCINVMAS`. Credentials are
intentionally not included in the repository.
