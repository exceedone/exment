<?php

namespace SimpleSoftwareIO\QrCode\Facades;

use Exceedone\Exment\Facades\QrCodeFacade;

/**
 * The class name of simplesoftwareio/simple-qrcode, kept for the code that still uses it (#1902).
 *
 * Exment "replaces" simplesoftwareio/simple-qrcode (composer.json): a site whose own
 * composer.json still requires it (made from an older exment-boilerplate) updates
 * without a conflict, and `SimpleSoftwareIO\QrCode\Facades\QrCode::format('png')->...`
 * keeps working. The QR code is made by Exment with bacon/bacon-qr-code 3.
 */
class QrCode extends QrCodeFacade
{
}
