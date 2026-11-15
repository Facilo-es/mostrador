<?php
/**
 * Model withdrawal form, TRLGDCU Annex B (consolidated BOE text).
 * The es_ES translation is the BOE text word for word. {shop} is replaced with the shop's name, full address and email.
 *
 * @package Mostrador
 */

defined( 'ABSPATH' ) || exit;

return array(
	'title' => __( 'Model withdrawal form', 'mostrador' ),
	'lines' => array(
		__( '(complete and return this form only if you wish to withdraw from the contract)', 'mostrador' ),
		/* translators: {shop} is replaced with the shop's name, full address and email. Keep it as is. */
		__( '– To {shop}:', 'mostrador' ),
		__( '– I/We (*) hereby give notice that I/We (*) withdraw from my/our (*) contract of sale of the following goods/for the provision of the following service (*)', 'mostrador' ),
		__( '– Ordered on/received on (*)', 'mostrador' ),
		__( '– Name of consumer(s)', 'mostrador' ),
		__( '– Address of consumer(s)', 'mostrador' ),
		__( '– Signature of consumer(s) (only if this form is notified on paper)', 'mostrador' ),
		__( '– Date', 'mostrador' ),
		__( '(*) Delete as appropriate.', 'mostrador' ),
	),
);
