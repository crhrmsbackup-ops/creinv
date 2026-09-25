# e-Invoice setup

1. Copy `application/config/einvoice_local.php.example` to
   `application/config/einvoice_local.php` and fill in the NIC/GSP sandbox or
   production credentials. AppKey is generated automatically as a fresh
   random 32-byte value for each authentication request. The supplied public key is already at
   `application/config/keys/einv_public_key.pem`.
2. Run `database/einvoice_oracle.sql` once as the `CRERP` schema owner.
3. From the Windows application, call
   `index.php/einvoice/create/CRG/DOI/26-27/000010`. The complete path is
   treated as the invoice number; slashes are not discarded. A query-string
   call is also supported: `index.php/einvoice/create?invoice=CRG%2FDOI%2F26-27%2F000010`.

The endpoint accepts only a URL invoice number and does not need a POST body.
It returns JSON and writes the IRN, acknowledgement, signed QR code, e-way
bill values, status, and full response into `DOCINVMAS`. Credentials are
intentionally not included in the repository.
