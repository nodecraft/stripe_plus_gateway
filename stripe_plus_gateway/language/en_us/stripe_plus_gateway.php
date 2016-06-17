<?php
// Errors
$lang['Stripe_plus_gateway.!error.auth'] = "Could not authenticate with the Stripe gateway.";
$lang['Stripe_plus_gateway.!error.live_api_key.empty'] = "Please enter a live API Key.";
$lang['Stripe_plus_gateway.!error.test_api_key.empty'] = "Please enter a test API Key.";
$lang['Stripe_plus_gateway.!error.environment.format'] = "Please select a valid API key for use.";
$lang['Stripe_plus_gateway.!error.json_required'] = "The JSON extension is required for this gateway.";
$lang['Stripe_plus_gateway.!error.refund.generic'] = "Request to process refund failed.";
$lang['Stripe_plus_gateway.!error.declined.generic'] = "Your card was declined.";

$lang['Stripe_plus_gateway.!error.customer.deleted'] = "The requested customer has been manually deleted.";

$lang['Stripe_plus_gateway.name'] = "Stripe";

// Settings
$lang['Stripe_plus_gateway.live_api_key'] = "Live API Secret Key";
$lang['Stripe_plus_gateway.tooltip_live_api_key'] = "Your API Secret Key is specific to either live or test mode. Be sure you are using the correct key.";
$lang['Stripe_plus_gateway.test_api_key'] = "Test API Secret Key";
$lang['Stripe_plus_gateway.tooltip_test_api_key'] = "Your API Secret Key is specific to either live or test mode. Be sure you are using the correct key.";
$lang['Stripe_plus_gateway.environment'] = "Select which environment to use.";
$lang['Stripe_plus_gateway.tooltip_environment'] = "The current environment to use with Stripe (Live or Test). Use test during development.";
//$lang['Stripe_plus_gateway.stored'] = "Store Card Information Offsite";
//$lang['Stripe_plus_gateway.tooltip_stored'] = "Check this box to store payment account card information with Stripe rather than within Blesta.";
?>