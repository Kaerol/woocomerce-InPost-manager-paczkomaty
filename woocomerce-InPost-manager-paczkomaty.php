<?php

/**
 * Plugin Name: InPost-manager-paczkomaty - export data from selected orders to csv format accepted by InPost - https://manager.paczkomaty.pl
 */

if (!defined('ABSPATH')) {
	exit;
}

define('WOO_ORDERS_INPOST_MANAGER_PACZKOMATY_DIR', WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . 'woocomerce-InPost-manager-paczkomaty' . DIRECTORY_SEPARATOR);
define('WOO_ORDERS_INPOST_MANAGER_PACZKOMATY_EXPORT_FILE_NAME', 'exported.csv');
define('WOO_ORDERS_INPOST_MANAGER_PACZKOMATY_EXPORT_LOCAION_NAME', 'InPost-manager');
define('WOO_ORDERS_INPOST_MANAGER_PACZKOMATY_EXPORT_DIR_PATH', WOO_ORDERS_INPOST_MANAGER_PACZKOMATY_DIR . '/../../../' . WOO_ORDERS_INPOST_MANAGER_PACZKOMATY_EXPORT_LOCAION_NAME);

const IN_POST_CSV_HEADER = 'e-mail;telefon;rozmiar;paczkomat;numer_referencyjny;dodatkowa_ochrona;za_pobraniem;imie_i_nazwisko;nazwa_firmy;ulica;kod_pocztowy;miejscowosc;typ_przesylki;paczka_w_weekend';

add_filter('bulk_actions-edit-shop_order', 'bulk_woocomerce_inpost_manager_paczkomaty', 20, 1);
function bulk_woocomerce_inpost_manager_paczkomaty($actions)
{
	$actions['bulk_woocomerce_inpost_manager_paczkomaty'] = 'Eksportuj do CSV InPost manager-paczkomaty';
	return $actions;
}

// Make the action from selected orders
add_filter('handle_bulk_actions-edit-shop_order', 'create_export_for__inpost_manager_paczkomaty', 10, 3);
function create_export_for__inpost_manager_paczkomaty($redirect_to, $action, $post_ids)
{
	if ($action !== 'bulk_woocomerce_inpost_manager_paczkomaty')
		return $redirect_to; // Exit

	global $wpdb;

	$filePath = WOO_ORDERS_INPOST_MANAGER_PACZKOMATY_EXPORT_DIR_PATH . '/' . WOO_ORDERS_INPOST_MANAGER_PACZKOMATY_EXPORT_FILE_NAME;

	file_put_contents($filePath, "");
	$file = fopen($filePath, 'a');

	fwrite($file, IN_POST_CSV_HEADER);
	fwrite($file, PHP_EOL);

	$orders = [];

	foreach ($post_ids as $post_id) {
		$sql = getSQLOrderProductDetails_InPost($post_id);
		$orderProductDetails = $wpdb->get_results($sql);

		for ($i = 0; $i < count($orderProductDetails); $i++) {
			$productDetails = $orderProductDetails[$i];
			$order_id = $productDetails->order_id;

			$inPostLine = [];
			if (isset($orders[$order_id])) {
				$inPostLine = $orders[$order_id];
				$inPostLine['ref_number'] .= ' | ';
			}

			$inPostLine['first_name'] = $productDetails->first_name;
			$inPostLine['last_name'] = $productDetails->last_name;
			$inPostLine['email'] = $productDetails->email;

			$phone = preg_replace('/[^0-9.]/', '', $productDetails->phone);
			$inPostLine['phone'] = substr($phone, -9);
			$inPostLine['inpost'] =  inPostAddressToParcelLockerCode($productDetails->inpost);

			$inPostLine['ref_number'] .= toNrRef($productDetails->sku,  $productDetails->quantity, $productDetails->size);

			$orders[$order_id] = $inPostLine;
		}
	}

	foreach ($orders as $o) {
		//$csvLine = 'e-mail;telefon;rozmiar;paczkomat;numer_referencyjny;dodatkowa_ochrona;za_pobraniem;imie_i_nazwisko;nazwa_firmy;ulica;kod_pocztowy;miejscowosc;typ_przesylki;paczka_w_weekend';
		$csvLine = $o['email'] . ';' . $o['phone'] . ';A;' . $o['inpost'] . ';' . trim($o['ref_number']) . ';;;' . $o['first_name'] . ' ' . $o['last_name'] . ';;;;;paczkomaty;NIE';
		fwrite($file, $csvLine);
		fwrite($file, PHP_EOL);
	}
	fclose($file);

	return 'https://zlotlagow.pl/' . WOO_ORDERS_INPOST_MANAGER_PACZKOMATY_EXPORT_LOCAION_NAME . '/' . WOO_ORDERS_INPOST_MANAGER_PACZKOMATY_EXPORT_FILE_NAME;
}

function getSQLOrderProductDetails_InPost($order_id)
{
	$sql = 'SELECT DISTINCT 
			wp.ID as `order_id`, wpm1.meta_value as `first_name`, wpm2.meta_value as `last_name`, wpm3.meta_value as `email`, wpm4.meta_value as `phone`, wpm5.meta_value as `inpost`,
			wpoim2.meta_value as `quantity`, UPPER(wpoim3.meta_value) as `size`, wpm6.meta_value as `sku`
			FROM wp_posts wp
			inner join wp_postmeta wpm1 on wp.id = wpm1.post_id and wpm1.meta_key = \'_billing_first_name\'
			inner join wp_postmeta wpm2 on wp.id = wpm2.post_id and wpm2.meta_key = \'_billing_last_name\'
			inner join wp_postmeta wpm3 on wp.id = wpm3.post_id and wpm3.meta_key = \'_billing_email\'
			inner join wp_postmeta wpm4 on wp.id = wpm4.post_id and wpm4.meta_key = \'_billing_phone\'
			inner join wp_postmeta wpm5 on wp.id = wpm5.post_id and wpm5.meta_key = \'inpost_place\'
			left outer join wp_woocommerce_order_items wpoi on wp.ID = wpoi.order_id and wpoi.order_item_type = \'line_item\'
			left outer join wp_woocommerce_order_itemmeta wpoim1 on wpoi.order_item_id = wpoim1.order_item_id and wpoim1.meta_key = \'_product_id\' and wpoim1.meta_value in (\'1637\', \'1633\', \'1626\', \'1619\')
			left outer join wp_postmeta wpm6 on CAST(wpoim1.meta_value AS INT) = wpm6.post_id and wpm6.meta_key = \'_sku\'
			left outer join wp_woocommerce_order_itemmeta wpoim2 on wpoi.order_item_id = wpoim2.order_item_id and wpoim2.meta_key = \'_qty\'
			left outer join wp_woocommerce_order_itemmeta wpoim3 on wpoi.order_item_id = wpoim3.order_item_id and wpoim3.meta_key = \'pa_rozmiar\'
			where wpoim1.meta_value is not null and wp.ID = ' . $order_id;

	return $sql;
}

function inPostAddressToParcelLockerCode($inPostAddress)
{
	// Sportowa 1, 14-522 Gdańsk, SAX905Y

	$arr = explode(',', $inPostAddress);
	$parcelLockerCode = trim($arr[count($arr) - 1]);

	return $parcelLockerCode;
}


function toNrRef($sku, $quantity, $size)
{
	// KZ-MT-2023-P
	$arr = explode('-', $sku);
	if (count($arr) < 4) {
		return '>> BLAD <<';
	}

	$sex = preg_replace('/[^MD]/', '', $arr[1]);

	$nrRef = trim($sex . ',' . $arr[3]);

	return strtoupper($quantity . $size . ',' . $nrRef);
}
