<?php
/**
 * Plugin Name: Cobeia WP REST API - Swagger generate
 * Description: Cobeia Swagger schema for the WP REST API
 * Author: Vincent Bathellier
 * Author URI: 
 * Version: 1.0.1
 * Plugin URI: https://github.com/Achilles4400/wp-api-swaggerui.git
 * License: Cobeia
 */

function swagger_rest_api_init() {

	if ( class_exists( 'WP_REST_Controller' )
		&& ! class_exists( 'WP_REST_Swagger_Controller' ) ) {
		require_once dirname( __FILE__ ) . '/lib/class-wp-rest-swagger-controller.php';
	}


	$swagger_controller = new WP_REST_Swagger_Controller();
	$swagger_controller->register_routes();

	

}

add_action( 'rest_api_init', 'swagger_rest_api_init', 11 );
