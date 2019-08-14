<?php

class Gotags_Gotags_Model_Api extends Mage_Api_Model_Resource_Abstract {

	protected $_storeId;

	/**
	 * Find a product by it's rewrite
	 *
	 * @param array $filters
	 *
	 * @return array
	 */
	public function search($filters) {

		if (!array_key_exists('sku', $filters) || !trim($filters['sku']))
			$this->_fault('filters_invalid', 'sku missing from filter array');

		$product = $this->_findProductBySku($filters['sku']);

		if (!$product)
			$this->_fault('product_not_found', "no product found for ({$filters['sku']}");

		return array(
			'filters' => $filters,
			'product' => $product->getData(),
		);

	}

	/**
	 * Use the rewrite index to find a product
	 *
	 * @param $sku
	 *
	 * @return Mage_Catalog_Model_Product
	 */
	protected function _findProductBySku($sku) {

		$productid = Mage::getModel('catalog/product')->getIdBySku(trim($sku));
		if(!$productid)
			return null;

		$product = new Mage_Catalog_Model_Product();
		$product->load($productid);

		return $product->getId() ? $product : null;

	}

	/**
	 * Check the database and return valid users by their email address
	 *
	 * @param array $users
	 *
	 * @return array
	 */
	protected function _getValidUsers(array $users) {

		/**
		 * get the default store id
		 * @todo there should be a better way of doing this
		 */
		$websites = Mage::app()->getWebsites();
		$defaultId = array_shift($websites)->getId();

		$valid = array();

		foreach ($users as $_email) {

			if (!filter_var($_email, FILTER_VALIDATE_EMAIL))
				continue;

			/** @var Mage_Customer_Model_Customer $customer */
			$customer = Mage::getModel("customer/customer");
			$customer->setWebsiteId($defaultId);
			$customer->loadByEmail($_email);

			if ($customer && $customer->getId())
				$valid[] = $customer;

		}

		return $valid;

	}

	/**
	 * Returns the first store id
	 * @todo there should be a better way of doing this
	 * @return int
	 */
	protected function _getStoreId() {

		if (!$this->_storeId) {

			$stores = Mage::app()->getStores();
			$this->_storeId = array_shift($stores)->getId();

		}

		return $this->_storeId;

	}

	/**
	 * Lookup product and add it to the customer cart
	 *
	 * @param array $filters
	 *
	 * @return array
	 */
	public function addProduct($filters) {

		if (!array_key_exists('sku', $filters) || !trim($filters['sku']))
			$this->_fault('filters_invalid', 'sku missing from filter array');

		if (!array_key_exists('customers', $filters) || !trim($filters['customers']))
			$this->_fault('filters_invalid', 'customers missing from filter array');

		$filters['customers'] = explode(',', trim($filters['customers']));
		if (!count($filters['customers']))
			$this->_fault('filters_invalid', 'customers key passed but empty');

		$filters['customers'] = $this->_getValidUsers($filters['customers']);

		if (!count($filters['customers']))
			$this->_fault('filters_invalid', 'customers key passed but no valid customers found');


		/**
		 * find the product by sku
		 */

		/** @var Mage_Catalog_Model_Product $product */
		$product = $this->_findProductBySku($filters['sku']);
		if (!$product)
			$this->_fault('product_not_found', "Product with given sku ({$filters['sku']}) not found");

		if ($product->getTypeId() == 'bundle')
			$this->_fault('not_supported', "Bundled products are not supported");

		if ($product->getTypeId() == 'grouped')
			$this->_fault('not_supported', "Grouped products are not supported");

		/**
		 * If we have options, try to find the closest product attributes
		 */
		$optionsToUse = array();

		if (array_key_exists('options', $filters) && is_array($filters['options'])) {

			$filters['options'] = array_map('strtolower', $filters['options']);

			/**
			 * For the options to work, we need the configurable product
			 */
			if (!$product->isConfigurable()) {

				$parentIds = Mage::getResourceSingleton('catalog/product_type_configurable')->getParentIdsByChild($product->getId());

				/**
				 * try to find a configurable parent
				 */
				if (count($parentIds)) {

					$_parentLookup = new Mage_Catalog_Model_Product();
					$_parentLookup->load($parentIds[0]);
					$product = $_parentLookup->getId() ? $_parentLookup : null;

				}

			}

			/**
			 * With the configurable product, try to find the best suited options
			 */
			if($product->isConfigurable()) {

				$_bestSuitedAttributes = [];
				$_tmpOptions = $filters['options'];

				/** @var Mage_Catalog_Model_Product_Type_Configurable_Attribute $_attr */
				foreach ($product->getTypeInstance(true)->getConfigurableAttributes($product) as $_attr) {

					$_prices = $_attr->getPrices();
					if (is_array($_prices))
						foreach ($_prices as $_item) {

							foreach ($_tmpOptions as $_opt) {

								if ($_opt == strtolower($_item['label'])) {

									$_bestSuitedAttributes[$_opt] = array(
										'attribute_id'    => $_attr->getAttributeId(),
										'attribute_code'  => $_attr->getAttributeCode(),
										'attribute_label' => $_attr->getLabel(),
										'option_id'       => $_item['value_index'],
									);

									unset($_tmpOptions[$_opt]);

								}

							}

						}

				}

				/**
				 * If these two are equal, we found the correct product!
				 * convert the attributes to the array required later
				 */
				$optionsToUse = array('super_attribute' => array());
				if (count($_bestSuitedAttributes) == count($filters['options'])) {

					foreach ($_bestSuitedAttributes as $_attr)
						$optionsToUse['super_attribute'][(int) $_attr['attribute_id']] = (int) $_attr['option_id'];

				}

				if (empty($optionsToUse['super_attribute']))
					$this->_fault('product_not_found', "Configurable product found, but options didn't match");

			}

		}

		if (!$product)
			$this->_fault('product_not_found', "no product found for ({$filters['sku']}");

		if ($product->isConfigurable() && empty($optionsToUse))
			$this->_fault('product_not_found', "Configurable product given, but options were not provided");

		/**
		 * get the product and add it to the customer carts
		 */
		$_varien = new Varien_Object(array_merge(array(
			'product' => $product->getId(),
			'qty'     => 1,
		), $optionsToUse));

		/** @var Mage_Customer_Model_Customer $_customer */
		foreach ($filters['customers'] as $_customer) {

			try {

				/**
				 * try to find a cart for the customer
				 */
				/** @var Mage_Sales_Model_Resource_Quote_Collection $_quoteLookup */
				$_quoteLookup = Mage::getModel('sales/quote')->getCollection();
				$_quoteLookup->addFieldToSelect('entity_id');
				$_quoteLookup->addFieldToFilter('customer_id', $_customer->getId());
				$_quoteLookup->addFieldToFilter('is_active', 1);
				$_quoteLookup->addOrder('entity_id', 'DESC');
				$_customerQuoteId = $_quoteLookup->load()->getFirstItem()->getId();


				/**
				 * no cart found so create one
				 */
				if (!$_customerQuoteId) {

					/** @var Mage_Checkout_Model_Cart_Api $cart_api */
					$cart_api = Mage::getModel('checkout/cart_api');
					$_customerQuoteId = $cart_api->create($this->_getStoreId());

				}

				$_currentCart = Mage::getModel('sales/quote')->loadByIdWithoutStore($_customerQuoteId);
				$_currentCart->setIsActive(true);

				/**
				 * Cloning the product is required as it gets modified somewhere down the code pile
				 */
				$_temp = clone $product;
				$res = $_currentCart->addProduct($_temp, $_varien);

				if(is_string($res)) // a string thrown is an error
					$this->_fault('cart_error', $res);

				$_currentCart->setCustomer($_customer);
				$_currentCart->assignCustomer($_customer);
				$_currentCart->collectTotals();
				$_currentCart->save();

			} catch(Exception $e) {
				$this->_fault('cart_error', $e->getMessage());
			}

		}

		return array(
			'sku'   => $filters['sku'],
			'customers' => array_map(function ($item) {
				return $item->getData();
			}, $filters['customers']),
			'product'   => $product->getData(),
			'options'   => $_varien->getData(),
		);

	}
}