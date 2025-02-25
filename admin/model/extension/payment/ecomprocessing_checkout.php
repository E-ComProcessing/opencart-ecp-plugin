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

use Genesis\Api\Constants\Transaction\Parameters\Threeds\V2\Control\ChallengeIndicators;
use Genesis\Api\Constants\Transaction\Parameters\ScaExemptions;
use Genesis\Genesis;

if (!class_exists('EcomprocessingHelper')) {
	require_once DIR_APPLICATION . "model/extension/payment/ecomprocessing/EcomprocessingHelper.php";
}

/**
 * Backend model for the "ecomprocessing Checkout" module
 *
 * @package EcomprocessingCheckout
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.LongClassName)
 */
class ModelExtensionPaymentEcomprocessingCheckout extends Model
{
	/**
	 * Holds the current module version
	 * Will be displayed on Admin Settings Form
	 *
	 * @var string
	 */
	protected $module_version = '1.7.0';

	/**
	 * Perform installation logic
	 *
	 * @return void
	 */
	public function install()
	{
		$this->db->query("
			CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "ecomprocessing_checkout_transactions` (
			  `unique_id` VARCHAR(255) NOT NULL,
			  `reference_id` VARCHAR(255) NOT NULL,
			  `order_id` INT(11) NOT NULL,
			  `type` CHAR(32) NOT NULL,
			  `mode` CHAR(255) NOT NULL,
			  `timestamp` DATETIME NOT NULL,
			  `status` CHAR(32) NOT NULL,
			  `message` VARCHAR(255) NULL,
			  `technical_message` VARCHAR(255) NULL,
			  `terminal_token` VARCHAR(255) NULL,
			  `amount` DECIMAL( 15, 4 ) DEFAULT NULL,
			  `currency` CHAR(3) NULL,
			  PRIMARY KEY (`unique_id`)
			) ENGINE=MyISAM DEFAULT COLLATE=utf8_general_ci;
		");
		$this->db->query("
			CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "ecomprocessing_checkout_consumers` (
			  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
			  `customer_email` varchar(255) NOT NULL,
			  `consumer_id` int(10) unsigned NOT NULL,
			  PRIMARY KEY (`id`),
			  UNIQUE KEY `customer_email` (`customer_email`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Tokenization consumers in Genesis';
		");
		$this->db->query("
			CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "ecomprocessing_checkout_cronlog` (
			  `log_entry_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
			  `pid` INT(10) UNSIGNED NOT NULL,
			  `start_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			  `run_time` VARCHAR(10) DEFAULT NULL,
			  PRIMARY KEY (`log_entry_id`)
			) ENGINE=MyISAM DEFAULT COLLATE=utf8_general_ci;
		");
		$this->db->query("
			CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "ecomprocessing_checkout_cronlog_transactions` (
			  `order_recurring_transaction_id` int(11) NOT NULL,
			  `order_id` INT(11) NOT NULL,
			  `log_entry_id` INT(10) UNSIGNED NOT NULL,
			  PRIMARY KEY (`order_recurring_transaction_id`),
			  KEY `order_id` (`order_id`),
			  KEY `log_entry_id` (`log_entry_id`)
			) ENGINE=MyISAM DEFAULT COLLATE=utf8_general_ci;
		");
	}

	/**
	 * Perform uninstall logic
	 *
	 * @return void
	 */
	public function uninstall()
	{
		// Keep transaction data
		//$this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "ecomprocessing_checkout_transactions`;");

		$this->load->model('setting/setting');

		$this->model_setting_setting->deleteSetting('ecomprocessing_checkout');
	}

	/**
	 * Get saved transaction by id
	 *
	 * @param string $reference_id UniqueId of the transaction
	 *
	 * @return mixed bool on fail, row on success
	 */
	public function getTransactionById($reference_id)
	{
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "ecomprocessing_checkout_transactions` WHERE `unique_id` = '" . $this->db->escape($reference_id) . "' LIMIT 1");

		if ($query->num_rows) {
			return reset($query->rows);
		}

		return false;
	}

	/**
	 * Get the sum of the ammount for a list of transaction types and status
	 * @param int $order_id
	 * @param string $reference_id
	 * @param array $types
	 * @param string $status
	 * @return decimal
	 */
	public function getTransactionsSumAmount($order_id, $reference_id, $types, $status) {
		$transactions = $this->getTransactionsByTypeAndStatus($order_id, $reference_id, $types, $status);
		$total_amount = 0;

		if ($transactions) {
			/** @var $transaction */
			foreach ($transactions as $transaction) {
				$total_amount += $transaction['amount'];
			}
		}

		return $total_amount;
	}

	/**
	 * Get the detailed transactions list of an order for transaction types and status
	 * @param int $order_id
	 * @param string $reference_id
	 * @param array $transaction_types
	 * @param string $status
	 * @return array
	 */

	public function getTransactionsByTypeAndStatus($order_id, $reference_id, $transaction_types, $status) {
		$query = $this->db->query("SELECT
                                      *
                                    FROM `" . DB_PREFIX . "ecomprocessing_checkout_transactions` as t
                                    WHERE (t.`order_id` = '" . abs(intval($order_id)) . "') and " .
			(!empty($reference_id)	? " (t.`reference_id` = '" . $reference_id . "') and " : "") . "
                                        (t.`type` in ('" . (is_array($transaction_types) ? implode("','", $transaction_types) : $transaction_types) . "')) and
                                        (t.`status` = '" . $status . "')
                                    ");

		if ($query->num_rows) {
			return $query->rows;
		}

		return false;

	}

	/**
	 * Get saved transactions by order id
	 *
	 * @param int $order_id OrderId
	 *
	 * @return mixed bool on fail, rows on success
	 */
	public function getTransactionsByOrder($order_id)
	{
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "ecomprocessing_checkout_transactions` WHERE `order_id` = '" . abs(intval($order_id)) . "'");

		if ($query->num_rows) {
			return $query->rows;
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
		} catch (\Exception $exception) {
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
		} catch (\Exception $exception) {
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
			$data = EcomprocessingHelper::sanitizeData($data, $this);

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
		} catch (\Exception $exception) {
			$this->logEx($exception);
		}
	}

	/**
	 * Send Capture transaction to the Gateway
	 *
	 * @param string $type
	 * @param string $reference_id ReferenceId
	 * @param string $amount Amount to be refunded
	 * @param string $currency Currency for the refunded amount
	 * @param string $usage Usage (optional text)
	 * @param int    $order_id
	 * @param string $token Terminal token of the initial transaction
	 *
	 * @return object
	 */
	public function capture($type, $reference_id, $amount, $currency, $usage, $order_id, $token = null)
	{
		try {
			$this->bootstrap($token);

			$genesis = new Genesis(
				\Genesis\Api\Constants\Transaction\Types::getCaptureTransactionClass($type)
			);

			$genesis
				->request()
				->setTransactionId(
					$this->genTransactionId('ocart-')
				)
				->setRemoteIp(
					$this->request->server['REMOTE_ADDR']
				)
				->setUsage($usage)
				->setReferenceId($reference_id)
				->setAmount($amount)
				->setCurrency($currency);

			if ($type === \Genesis\Api\Constants\Transaction\Types::KLARNA_AUTHORIZE) {
				$genesis->request()->setItems($this->getKlarnaReferenceAttributes($currency, $order_id));
			}

			$genesis->execute();

			return $genesis->response()->getResponseObject();
		} catch (\Exception $exception) {
			$this->logEx($exception);

			return $exception->getMessage();
		}
	}

	/**
	 * Send Refund transaction to the Gateway
	 *
	 * @param string $type Transaction Type
	 * @param string $reference_id ReferenceId
	 * @param string $amount Amount to be refunded
	 * @param string $currency Currency for the refunded amount
	 * @param string $usage Usage (optional text)
	 * @param string $token Terminal token of the initial transaction
	 * @param int    $order_id
	 *
	 * @return object
	 */
	public function refund($type, $reference_id, $amount, $currency, $usage = '', $token = null, $order_id = 0)
	{
		try {
			$this->bootstrap($token);

			$genesis = new Genesis(
				\Genesis\Api\Constants\Transaction\Types::getRefundTransactionClass($type)
			);

			$genesis
				->request()
				->setTransactionId(
					$this->genTransactionId('ocart-')
				)
				->setRemoteIp(
					$this->request->server['REMOTE_ADDR']
				)
				->setUsage($usage)
				->setReferenceId($reference_id)
				->setAmount($amount)
				->setCurrency($currency);

			if ($type === \Genesis\Api\Constants\Transaction\Types::KLARNA_CAPTURE) {
				$genesis->request()->setItems($this->getKlarnaReferenceAttributes($currency, $order_id));
			}

			$genesis->execute();

			return $genesis->response()->getResponseObject();
		} catch (Exception $exception) {
			$this->logEx($exception);

			return $exception->getMessage();
		}
	}

	/**
	 * @param $currency
	 * @param $order_id
	 * @return \Genesis\Api\Request\Financial\Alternatives\Klarna\Items
	 * @throws \Genesis\Exceptions\ErrorParameter
	 */
	protected function getKlarnaReferenceAttributes($currency, $order_id)
	{
		$this->load->model('sale/order');

		$product_order_info = $this->model_sale_order->getOrderProducts($order_id);
		$order_totals       = $this->model_sale_order->getOrderTotals($order_id);
		$product_info       = $this->getProductsInfo(
			array_map(
				function($value) {
					return $value['product_id'];
				},
				$product_order_info
			)
		);

		return EcomprocessingHelper::getKlarnaCustomParamItems(
			array(
				'currency'   => $currency,
				'additional' => array (
					'product_order_info' => $product_order_info,
					'product_info'       => $product_info,
					'order_totals'       => $order_totals
				)
			)
		);
	}

	/**
	 * Send Void transaction to the Gateway
	 *
	 * @param string $reference_id ReferenceId
	 * @param string $usage Usage (optional text)
	 * @param string $token Terminal token of the initial transaction
	 *
	 * @return object
	 */
	public function void($reference_id, $usage = '', $token = null)
	{
		try {
			$this->bootstrap($token);

			$genesis = new Genesis('Financial\Void');

			$genesis
				->request()
				->setTransactionId(
					$this->genTransactionId('ocart-')
				)
				->setRemoteIp(
					$this->request->server['REMOTE_ADDR']
				)
				->setUsage($usage)
				->setReferenceId($reference_id);

			$genesis->execute();

			return $genesis->response()->getResponseObject();
		} catch (\Exception $exception) {
			$this->logEx($exception);

			return $exception->getMessage();
		}
	}

	/**
	 * Get localized transaction types for Genesis
	 *
	 * @return array
	 */
	public function getTransactionTypes()
	{
		$data = array();

		$this->bootstrap();

		$this->load->language('extension/payment/ecomprocessing_checkout');

		$transaction_types = \Genesis\Api\Constants\Transaction\Types::getWPFTransactionTypes();
		$excluded_types    = EcomprocessingHelper::getRecurringTransactionTypes();

		// Exclude SDD Recurring
		array_push($excluded_types, \Genesis\Api\Constants\Transaction\Types::SDD_INIT_RECURRING_SALE);

		// Exclude PPRO transaction. This is not standalone transaction type
		array_push($excluded_types, \Genesis\Api\Constants\Transaction\Types::PPRO);

		// Exclude GooglePay transaction. In this way Google Pay Payment types will be introduced
		array_push($excluded_types, \Genesis\Api\Constants\Transaction\Types::GOOGLE_PAY);

		// Exclude PayPal transaction. In this way PayPal Payment types will be introduced
		array_push($excluded_types, \Genesis\Api\Constants\Transaction\Types::PAY_PAL);

		// Exclude Apple Pay transaction. This is not standalone transaction type
		array_push($excluded_types, \Genesis\Api\Constants\Transaction\Types::APPLE_PAY);

		// Exclude Transaction Types
		$transaction_types = array_diff($transaction_types, $excluded_types);

		// Add Google Payment types
		$google_pay_types = array_map(
			function ($type) {
				return EcomprocessingHelper::GOOGLE_PAY_TRANSACTION_PREFIX . $type;
			},
			[
				\Genesis\Api\Constants\Transaction\Parameters\Mobile\GooglePay\PaymentTypes::AUTHORIZE,
				\Genesis\Api\Constants\Transaction\Parameters\Mobile\GooglePay\PaymentTypes::SALE
			]
		);

		// Add PayPal Payment types
		$paypal_types = array_map(
			function ($type) {
				return EcomprocessingHelper::PAYPAL_TRANSACTION_PREFIX . $type;
			},
			[
				\Genesis\Api\Constants\Transaction\Parameters\Wallets\PayPal\PaymentTypes::AUTHORIZE,
				\Genesis\Api\Constants\Transaction\Parameters\Wallets\PayPal\PaymentTypes::SALE,
				\Genesis\Api\Constants\Transaction\Parameters\Wallets\PayPal\PaymentTypes::EXPRESS
			]
		);

		// Add Apple Pay Payment types
		$apple_pay_types = array_map(
			function ($type) {
				return EcomprocessingHelper::APPLE_PAY_TRANSACTION_PREFIX . $type;
			},
			[
				\Genesis\Api\Constants\Transaction\Parameters\Mobile\ApplePay\PaymentTypes::AUTHORIZE,
				\Genesis\Api\Constants\Transaction\Parameters\Mobile\ApplePay\PaymentTypes::SALE
			]
		);

		$transaction_types = array_merge(
			$transaction_types,
			$google_pay_types,
			$paypal_types,
			$apple_pay_types
		);
		asort($transaction_types);

		foreach ($transaction_types as $type) {
			$name = $this->language->get('text_transaction_' . $type);

			if (strpos($name, 'text_transaction') !== false) {
				if (\Genesis\Api\Constants\Transaction\Types::isValidTransactionType($type)) {
					$name = \Genesis\Api\Constants\Transaction\Names::getName($type);
				} else {
					$name = strtoupper($type);
				}
			}

			$data[$type] = array(
				'id'   => $type,
				'name' => $name
			);
		}

		return $data;
	}

	/**
	 * Returns formatted array with available Bank codes
	 *
	 * @return array
	 */
	public function getBankCodes()
	{
		$data = [];
		$available_bank_codes = EcomprocessingHelper::getAvailableBankCodes();

		foreach ($available_bank_codes as $value => $label) {
			$data[] = [
				'id'   => $value,
				'name' => $label
			];
		}

		return $data;
	}

	/**
	 * Returns formatted array with available threeds challenge indicators
	 *
	 * @return array
	 */
	public function getThreedsChallengeIndicators()
	{
		$data                 = [];
		$challenge_indicators = [
			ChallengeIndicators::NO_PREFERENCE          => 'No preference',
			ChallengeIndicators::NO_CHALLENGE_REQUESTED => 'No challenge requested',
			ChallengeIndicators::PREFERENCE             => 'Preference',
			ChallengeIndicators::MANDATE                => 'Mandate'
		];

		foreach ($challenge_indicators as $value => $label) {
			$data[] = [
				'id'   => $value,
				'name' => $label
			];
		}

		return $data;
	}

	/**
	 * Returns formatted array with available SCA Exemptions
	 *
	 * @return array
	 */
	public function getScaExemptions()
	{
		$data           = [];
		$sca_exemptions = [
			ScaExemptions::EXEMPTION_LOW_RISK  => 'Low risk',
			ScaExemptions::EXEMPTION_LOW_VALUE => 'Low value',
		];

		foreach ($sca_exemptions as $value => $label) {
			$data[] = [
				'id'   => $value,
				'name' => $label
			];
		}

		return $data;

	}

	/**
	 * Get localized recurring transaction types for Genesis
	 *
	 * @return array
	 */
	public function getRecurringTransactionTypes()
	{
		$this->bootstrap();

		$this->load->language('extension/payment/ecomprocessing_checkout');

		return array(
			\Genesis\Api\Constants\Transaction\Types::INIT_RECURRING_SALE    => array(
				'id'   => \Genesis\Api\Constants\Transaction\Types::INIT_RECURRING_SALE,
				'name' => $this->language->get(
					EcomprocessingHelper::TRANSACTION_LANGUAGE_PREFIX .
					\Genesis\Api\Constants\Transaction\Types::INIT_RECURRING_SALE
				)
			),
			\Genesis\Api\Constants\Transaction\Types::INIT_RECURRING_SALE_3D => array(
				'id'   => \Genesis\Api\Constants\Transaction\Types::INIT_RECURRING_SALE_3D,
				'name' => $this->language->get(
					EcomprocessingHelper::TRANSACTION_LANGUAGE_PREFIX .
					\Genesis\Api\Constants\Transaction\Types::INIT_RECURRING_SALE_3D
				)
			),
		);
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
	 * Bootstrap Genesis Library
	 *
	 * @param string $token Terminal token
	 *
	 * @return void
	 */
	public function bootstrap($token = null)
	{
		if (!class_exists('\Genesis\Genesis', false)) {
			include DIR_APPLICATION . '/model/extension/payment/ecomprocessing/genesis/vendor/autoload.php';

			\Genesis\Config::setEndpoint(
				\Genesis\Api\Constants\Endpoints::ECOMPROCESSING
			);

			\Genesis\Config::setUsername(
				$this->config->get('ecomprocessing_checkout_username')
			);

			\Genesis\Config::setPassword(
				$this->config->get('ecomprocessing_checkout_password')
			);

			\Genesis\Config::setEnvironment(
				$this->config->get('ecomprocessing_checkout_sandbox') ? \Genesis\Api\Constants\Environments::STAGING : \Genesis\Api\Constants\Environments::PRODUCTION
			);
		}

		if (isset($token)) {
			\Genesis\Config::setToken((string)$token);
		}
	}

	/**
	 * Log Exception to a log file, if enabled
	 *
	 * @param $exception
	 */
	public function logEx($exception)
	{
		if ($this->config->get('ecomprocessing_checkout_debug')) {
			$log = new Log('Ecomprocessing_checkout.log');
			$log->write($this->jTraceEx($exception));
		}
	}

	/**
	 * jTraceEx() - provide a Java style exception trace
	 * @param $exception Exception
	 * @param $seen - array passed to recursive calls to accumulate trace lines already seen
	 *                     leave as NULL when calling this function
	 * @return array of strings, one entry per trace line
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
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

	/**
	 * Retrieves the Module Method Version
	 *
	 * @return string
	 */
	public function getVersion()
	{
		return $this->module_version;
	}
}
