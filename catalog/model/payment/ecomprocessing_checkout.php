<?php
/*
 * Copyright (C) 2018 E-Comprocessing Ltd.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * @author      E-Comprocessing
 * @copyright   2018 E-Comprocessing Ltd.
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU General Public License, version 2 (GPL-2.0)
 */

require_once DIR_APPLICATION . 'model/payment/ecomprocessing/base_model.php';

/**
 * Front-end model for the "E-Comprocessing Checkout" module
 *
 * @package EComProcessingCheckout
 */
class ModelPaymentEComProcessingCheckout extends ModelPaymentEComProcessingBase
{
	/**
	 * Module Name
	 *
	 * @var string
	 */
	protected $module_name = 'ecomprocessing_checkout';

	/**
	 * Main method
	 *
	 * @param $address Order Address
	 * @param $total   Order Total
	 *
	 * @return array
	 */
	public function getMethod($address, $total)
	{
		$this->load->language('payment/ecomprocessing_checkout');

		$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "zone_to_geo_zone WHERE geo_zone_id = '" . (int)$this->config->get('ecomprocessing_checkout_geo_zone_id') . "' AND country_id = '" . (int)$address['country_id'] . "' AND (zone_id = '" . (int)$address['zone_id'] . "' OR zone_id = '0')");

		if ($this->config->get('ecomprocessing_checkout_total') > 0 && $this->config->get('ecomprocessing_checkout_total') > $total) {
			$status = false;
		} elseif (!$this->config->get('ecomprocessing_checkout_geo_zone_id')) {
			$status = true;
		} elseif ($query->num_rows) {
			$status = true;
		} else {
			$status = false;
		}

		$method_data = array();

		if ($status) {
			$method_data = array(
				'code'       => 'ecomprocessing_checkout',
				'title'      => $this->language->get('text_title'),
				'terms'      => '',
				'sort_order' => $this->config->get('ecomprocessing_checkout_sort_order')
			);
		}

		return $method_data;
	}

	/**
	 * Get saved transaction (from DB) by id
	 *
	 * @param $unique_id
	 *
	 * @return bool|mixed
	 */
	public function getTransactionById($unique_id)
	{
		if (isset($unique_id) && !empty($unique_id)) {
			$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "ecomprocessing_checkout_transactions` WHERE `unique_id` = '" . $this->db->escape($unique_id) . "' LIMIT 1");

			if ($query->num_rows) {
				return reset($query->rows);
			}
		}

		return false;
	}

	/**
	 * Add transaction to the database
	 *
	 * @param $data array
	 */
	public function addTransaction($data)
	{
		try {
			$fields = implode(', ', array_map(
					function ($value, $key) {
						return sprintf('`%s`', $key);
					},
					$data,
					array_keys($data)
				)
			);

			$values = implode(', ', array_map(
					function ($value) {
						return sprintf("'%s'", $value);
					},
					$data,
					array_keys($data)
				)
			);

			$this->db->query("
				INSERT INTO
					`" . DB_PREFIX . "ecomprocessing_checkout_transactions` (" . $fields . ")
				VALUES
					(" . $values . ")
			");
		} catch (Exception $exception) {
			$this->logEx($exception);
		}
	}

	/**
	 * Update existing transaction in the database
	 *
	 * @param $data array
	 */
	public function updateTransaction($data)
	{
		try {
			$fields = implode(', ', array_map(
					function ($value, $key) {
						return sprintf("`%s` = '%s'", $key, $value);
					},
					$data,
					array_keys($data)
				)
			);

			$this->db->query("
				UPDATE
					`" . DB_PREFIX . "ecomprocessing_checkout_transactions`
				SET
					" . $fields . "
				WHERE
				    `unique_id` = '" . $data['unique_id'] . "'
			");
		} catch (Exception $exception) {
			$this->logEx($exception);
		}
	}

	/**
	 * Sanitize transaction data and check
	 * whether an UPDATE or INSERT is required
	 *
	 * @param array $data
	 */
	public function populateTransaction($data = array())
	{
		try {
			$self = $this;

			// Sanitize the input data
			array_walk($data, function (&$column, &$value) use ($self) {
				$column = $self->db->escape($column);
				$value  = $self->db->escape($value);
			});

			// Check if transaction exists
			$insert_query = $this->db->query("
				SELECT
					*
				FROM
					`" . DB_PREFIX . "ecomprocessing_checkout_transactions`
				WHERE
					`unique_id` = '" . $data['unique_id'] . "'
			");

			if ($insert_query->rows) {
				$this->updateTransaction($data);
			} else {
				$this->addTransaction($data);
			}
		} catch (Exception $exception) {
			$this->logEx($exception);
		}
	}

	/**
	 * Send transaction to Genesis
	 *
	 * @param $data array Transaction Data
	 * @return mixed
	 * @throws Exception
	 * @throws \Genesis\Exceptions\ErrorAPI
	 */
	public function create($data)
	{
		try {
			$this->bootstrap();

			$genesis = new \Genesis\Genesis('WPF\Create');

			$genesis
				->request()
				->setTransactionId($data['transaction_id'])
				->setRemoteIp($data['remote_address'])
				// Financial
				->setCurrency($data['currency'])
				->setAmount($data['amount'])
				->setUsage($data['usage'])
				->setDescription($data['description'])
				// Personal
				->setCustomerEmail($data['customer_email'])
				->setCustomerPhone($data['customer_phone'])
				// URL
				->setNotificationUrl($data['notification_url'])
				->setReturnSuccessUrl($data['return_success_url'])
				->setReturnFailureUrl($data['return_failure_url'])
				->setReturnCancelUrl($data['return_cancel_url'])
				// Billing
				->setBillingFirstName($data['billing']['first_name'])
				->setBillingLastName($data['billing']['last_name'])
				->setBillingAddress1($data['billing']['address1'])
				->setBillingAddress2($data['billing']['address2'])
				->setBillingZipCode($data['billing']['zip'])
				->setBillingCity($data['billing']['city'])
				->setBillingState($data['billing']['state'])
				->setBillingCountry($data['billing']['country'])
				// Shipping
				->setShippingFirstName($data['shipping']['first_name'])
				->setShippingLastName($data['shipping']['last_name'])
				->setShippingAddress1($data['shipping']['address1'])
				->setShippingAddress2($data['shipping']['address2'])
				->setShippingZipCode($data['shipping']['zip'])
				->setShippingCity($data['shipping']['city'])
				->setShippingState($data['shipping']['state'])
				->setShippingCountry($data['shipping']['country'])
				->setLanguage($data['language']);

			$transaction_types = $this->isRecurringOrder() ? $this->getRecurringTransactionTypes() : $this->getTransactionTypes();

			foreach ($transaction_types as $type) {
				if (is_array($type)) {
					$genesis
						->request()
						->addTransactionType($type['name'], $type['parameters']);
				} else {
					$genesis
						->request()
						->addTransactionType($type);
				}
			}

			$genesis->execute();

			return $genesis->response()->getResponseObject();
		} catch (\Genesis\Exceptions\ErrorAPI $api) {
			throw $api;
		} catch (\Exception $exception) {
			$this->logEx($exception);

			return false;
		}
	}

	/**
	 * Genesis Request - Reconcile
	 *
	 * @param $unique_id string - Id of a Genesis Transaction
	 * @return mixed
	 * @throws Exception
	 * @throws \Genesis\Exceptions\ErrorAPI
	 */
	public function reconcile($unique_id)
	{
		try {
			$this->bootstrap();

			$genesis = new \Genesis\Genesis('WPF\Reconcile');

			$genesis->request()->setUniqueId($unique_id);

			$genesis->execute();

			return $genesis->response()->getResponseObject();
		} catch (\Genesis\Exceptions\ErrorAPI $api) {
			throw $api;
		} catch (\Exception $exception) {
			$this->logEx($exception);

			return false;
		}
	}

	/**
	 * Bootstrap Genesis Library
	 *
	 * @return void
	 */
	public function bootstrap()
	{
		// Look for, but DO NOT try to load via Auto-loader magic methods
		if (!class_exists('\Genesis\Genesis', false)) {
			include DIR_APPLICATION . '/../admin/model/payment/ecomprocessing/genesis/vendor/autoload.php';

			\Genesis\Config::setEndpoint(
				\Genesis\API\Constants\Endpoints::ECOMPROCESSING
			);

			\Genesis\Config::setUsername(
				$this->config->get('ecomprocessing_checkout_username')
			);

			\Genesis\Config::setPassword(
				$this->config->get('ecomprocessing_checkout_password')
			);

			\Genesis\Config::setEnvironment(
				$this->config->get('ecomprocessing_checkout_sandbox') ? \Genesis\API\Constants\Environments::STAGING : \Genesis\API\Constants\Environments::PRODUCTION
			);
		}
	}

	/**
	 * Generate Transaction Id based on the order id
	 * and salted to avoid duplication
	 *
	 * @param string $prefix
	 *
	 * @return string
	 */
	public function genTransactionId($prefix = '')
	{
		$hash = md5(microtime(true) . uniqid() . mt_rand(PHP_INT_SIZE, PHP_INT_MAX));

		return (string)$prefix . substr($hash, -(strlen($hash) - strlen($prefix)));
	}

	/**
	 * Get the current front-end language
	 *
	 * @return string
	 */
	public function getLanguage()
	{
		$language = isset($this->session->data['language']) ? $this->session->data['language'] : $this->config->get('config_language');

		$language_code = substr($language, 0, 2);

		$this->bootstrap();

		$is_available = @constant('\Genesis\API\Constants\i18n::' . strtoupper($language_code));

		if ($is_available) {
			return strtolower($language_code);
		} else {
			return 'en';
		}
	}

	/**
	 * Get a description-formatted list of products
	 * inside an order
	 *
	 * @param $order_id
	 * @return string
	 */
	public function getOrderProducts($order_id)
	{
		$order_product_query = $this->db->query("
            SELECT
                *
            FROM
                " . DB_PREFIX . "order_product
            WHERE
                order_id = '" . abs((int)$order_id) . "'
            ");

		$description = '';

		foreach ($order_product_query->rows as $order_product) {
			$description .= sprintf("%s (%s) x %d\r\n", $order_product['name'], $order_product['model'], $order_product['quantity']);
		}

		return $description;
	}

	/**
	 * Get the selected transaction types in array
	 *
	 * @return array
	 */
	public function getTransactionTypes()
	{
		$processed_list = array();

		$selected_types = $this->config->get('ecomprocessing_checkout_transaction_type');

		$alias_map = array(
			\Genesis\API\Constants\Payment\Methods::EPS         =>
				\Genesis\API\Constants\Transaction\Types::PPRO,
			\Genesis\API\Constants\Payment\Methods::GIRO_PAY    =>
				\Genesis\API\Constants\Transaction\Types::PPRO,
			\Genesis\API\Constants\Payment\Methods::PRZELEWY24  =>
				\Genesis\API\Constants\Transaction\Types::PPRO,
			\Genesis\API\Constants\Payment\Methods::QIWI        =>
				\Genesis\API\Constants\Transaction\Types::PPRO,
			\Genesis\API\Constants\Payment\Methods::SAFETY_PAY  =>
				\Genesis\API\Constants\Transaction\Types::PPRO,
			\Genesis\API\Constants\Payment\Methods::TELEINGRESO =>
				\Genesis\API\Constants\Transaction\Types::PPRO,
			\Genesis\API\Constants\Payment\Methods::TRUST_PAY   =>
				\Genesis\API\Constants\Transaction\Types::PPRO,
		);

		foreach ($selected_types as $selected_type) {
			if (array_key_exists($selected_type, $alias_map)) {
				$transaction_type = $alias_map[$selected_type];

				$processed_list[$transaction_type]['name'] = $transaction_type;

				$processed_list[$transaction_type]['parameters'][] = array(
					'payment_method' => $selected_type
				);
			} else {
				$processed_list[] = $selected_type;
			}
		}

		return $processed_list;
	}

	/**
	 * Get the selected transaction types in array
	 *
	 * @return array
	 */
	public function getRecurringTransactionTypes()
	{
		return $this->config->get('EComProcessing_checkout_recurring_transaction_type');
	}

	/**
	 * Get a Usage string with the Store Name
	 *
	 * @return string
	 */
	public function getUsage()
	{
		return sprintf('%s checkout transaction', $this->config->get('config_name'));
	}

	/**
	 * Log Exception to a log file, if enabled
	 *
	 * @param $exception
	 */
	public function logEx($exception)
	{
		if ($this->config->get('ecomprocessing_checkout_debug')) {
			$log = new Log('EComProcessing_checkout.log');
			$log->write($this->jTraceEx($exception));
		}
	}

	/**
	 * jTraceEx() - provide a Java style exception trace
	 * @param $exception Exception
	 * @param $seen - array passed to recursive calls to accumulate trace lines already seen
	 *                     leave as NULL when calling this function
	 * @return array of strings, one entry per trace line
	 *
	 * @SuppressWarnings(PHPMD)
	 */
	private function jTraceEx($exception, $seen = null)
	{
		$starter = ($seen) ? 'Caused by: ' : '';
		$result  = array();

		if (!$seen) $seen = array();

		$trace = $exception->getTrace();
		$prev  = $exception->getPrevious();

		$result[] = sprintf('%s%s: %s', $starter, get_class($exception), $exception->getMessage());

		$file = $exception->getFile();
		$line = $exception->getLine();

		while (true) {
			$current = "$file:$line";
			if (is_array($seen) && in_array($current, $seen)) {
				$result[] = sprintf(' ... %d more', count($trace) + 1);
				break;
			}
			$result[] = sprintf(' at %s%s%s(%s%s%s)',
				count($trace) && array_key_exists('class', $trace[0]) ? str_replace('\\', '.', $trace[0]['class']) : '',
				count($trace) && array_key_exists('class', $trace[0]) && array_key_exists('function', $trace[0]) ? '.' : '',
				count($trace) && array_key_exists('function', $trace[0]) ? str_replace('\\', '.', $trace[0]['function']) : '(main)',
				($line === null) ? $file : basename($file),
				($line === null) ? '' : ':',
				($line === null) ? '' : $line);
			if (is_array($seen))
				$seen[] = "$file:$line";
			if (!count($trace))
				break;
			$file = array_key_exists('file', $trace[0]) ? $trace[0]['file'] : 'Unknown Source';
			$line = (array_key_exists('file', $trace[0]) && array_key_exists('line', $trace[0]) && $trace[0]['line']) ? $trace[0]['line'] : null;
			array_shift($trace);
		}

		$result = join("\n", $result);

		if ($prev)
			$result .= "\n" . $this->jTraceEx($prev, $seen);

		return $result;
	}
}
