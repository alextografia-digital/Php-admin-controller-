<?php

class AdminImportController extends AdminImportControllerCore
{
    protected function productImportOne($info, $default_language_id, $id_lang, $force_ids, $regenerate, $shop_is_feature_active, $shop_ids, $match_ref, &$accessories, $validateOnly = false)
    {
        if (!$force_ids) {
            unset($info['id']);
        }

        $id_product = null;
        // Use product reference as key
        if (!empty($info['id'])) {
            $id_product = (int) $info['id'];
        } elseif ($match_ref && isset($info['reference'])) {
            $idProductByRef = (int) Db::getInstance()->getValue('
                                    SELECT p.`id_product`
                                    FROM `' . _DB_PREFIX_ . 'product` p
                                    ' . Shop::addSqlAssociation('product', 'p') . '
                                    WHERE p.`reference` = "' . pSQL($info['reference']) . '"
                                ', false);
            if ($idProductByRef) {
                $id_product = $idProductByRef;
            }
        }

        $product = new Product($id_product);

        // MODIFIED: We remove the error if the name is empty.
        // Logic for setting "n.d." will be handled in the Product class override.
        /*
        if (!$product->id && empty($info['name'])) {
            $this->errors[] = sprintf(
                $this->trans('Product with reference %1$s (ID: %2$s) could not be saved because the product name is missing.', [], 'Admin.Advparameters.Notification'),
                (!empty($info['reference'])) ? Tools::htmlentitiesUTF8($info['reference']) : 'null',
                !empty($info['id']) ? Tools::htmlentitiesUTF8($info['id']) : 'null'
            );

            return;
        }
        */

        if (isset($product->id) && $product->id && Product::existsInDatabase((int) $product->id, 'product')) {
            $product->loadStockData();
            $category_data = Product::getProductCategories((int) $product->id);

            if (is_array($category_data)) {
                foreach ($category_data as $tmp) {
                    if (!$product->category || is_array($product->category)) {
                        $product->category[] = $tmp;
                    }
                }
            }
        }

        AdminImportController::setEntityDefaultValues($product);
        AdminImportController::arrayWalk($info, ['AdminImportController', 'fillInfo'], $product);

        /** @var Product|null $product */
        if (!$product) {
            return;
        }

        if (!$shop_is_feature_active) {
            $product->shop = (int) Configuration::get('PS_SHOP_DEFAULT');
        } elseif (!isset($product->shop) || empty($product->shop)) {
            $product->shop = implode($this->multiple_value_separator, Shop::getContextListShopID());
        }

        if (!$shop_is_feature_active) {
            $product->id_shop_default = (int) Configuration::get('PS_SHOP_DEFAULT');
        } else {
            $product->id_shop_default = (int) Context::getContext()->shop->id;
        }

        // link product to shops
        foreach (explode($this->multiple_value_separator, $product->shop) as $shop) {
            if (!empty($shop) && !is_numeric($shop)) {
                $product->id_shop_list[] = Shop::getIdByName($shop);
            } elseif (!empty($shop)) {
                $product->id_shop_list[] = $shop;
            }
        }

        if ((int) $product->id_tax_rules_group != 0) {
            if (Validate::isLoadedObject(new TaxRulesGroup($product->id_tax_rules_group))) {
                $address = $this->context->shop->getAddress();
                $tax_manager = TaxManagerFactory::getManager($address, $product->id_tax_rules_group);
                $product_tax_calculator = $tax_manager->getTaxCalculator();
                $product->tax_rate = $product_tax_calculator->getTotalRate();
            } else {
                $this->addProductWarning(
                    'id_tax_rules_group',
                    $product->id_tax_rules_group,
                    $this->trans('Unknown tax rule group ID. You need to create a group with this ID first.', [], 'Admin.Advparameters.Notification')
                );
            }
        }
        if (isset($product->manufacturer) && is_numeric($product->manufacturer) && Manufacturer::manufacturerExists((int) $product->manufacturer)) {
            $product->id_manufacturer = (int) $product->manufacturer;
        } elseif (isset($product->manufacturer) && is_string($product->manufacturer) && !empty($product->manufacturer)) {
            if ($manufacturer = Manufacturer::getIdByName($product->manufacturer)) {
                $product->id_manufacturer = (int) $manufacturer;
            } else {
                $manufacturer = new Manufacturer();
                $manufacturer->name = $product->manufacturer;
                $manufacturer->active = true;
                if (($field_error = $manufacturer->validateFields(UNFRIENDLY_ERROR, true)) === true
                    && ($lang_field_error = $manufacturer->validateFieldsLang(UNFRIENDLY_ERROR, true)) === true
                    && !$validateOnly // Do not move this condition: previous tests should be played always, but next ->add() test should not be played in validateOnly mode
                    && $manufacturer->add()) {
                    $product->id_manufacturer = (int) $manufacturer->id;
                    $manufacturer->associateTo($product->id_shop_list);
                } else {
                    if (!$validateOnly) {
                        $this->errors[] = sprintf(
                            $this->trans('%1$s (ID: %2$s) cannot be saved', [], 'Admin.Advparameters.Notification'),
                            Tools::htmlentitiesUTF8($manufacturer->name),
                            !empty($manufacturer->id) ? $manufacturer->id : 'null'
                        );
                    }
                    if ($field_error !== true || isset($lang_field_error) && $lang_field_error !== true) {
                        $this->errors[] = ($field_error !== true ? $field_error : '') . (isset($lang_field_error) && $lang_field_error !== true ? $lang_field_error : '') .
                            Db::getInstance()->getMsgError();
                    }
                }
            }
        }

        if (isset($product->supplier) && is_numeric($product->supplier) && Supplier::supplierExists((int) $product->supplier)) {
            $product->id_supplier = (int) $product->supplier;
        } elseif (isset($product->supplier) && is_string($product->supplier) && !empty($product->supplier)) {
            if ($supplier = Supplier::getIdByName($product->supplier)) {
                $product->id_supplier = (int) $supplier;
            } else {
                $supplier = new Supplier();
                $supplier->name = $product->supplier;
                $supplier->active = true;

                if (($field_error = $supplier->validateFields(UNFRIENDLY_ERROR, true)) === true
                    && ($lang_field_error = $supplier->validateFieldsLang(UNFRIENDLY_ERROR, true)) === true
                    && !$validateOnly  // Do not move this condition: previous tests should be played always, but next ->add() test should not be played in validateOnly mode
                    && $supplier->add()) {
                    $product->id_supplier = (int) $supplier->id;
                    $supplier->associateTo($product->id_shop_list);
                } else {
                    if (!$validateOnly) {
                        $this->errors[] = sprintf(
                            $this->trans('%1$s (ID: %2$s) cannot be saved', [], 'Admin.Advparameters.Notification'),
                            Tools::htmlentitiesUTF8($supplier->name),
                            !empty($supplier->id) ? Tools::htmlentitiesUTF8($supplier->id) : 'null'
                        );
                    }
                    if ($field_error !== true || isset($lang_field_error) && $lang_field_error !== true) {
                        $this->errors[] = ($field_error !== true ? $field_error : '') . (isset($lang_field_error) && $lang_field_error !== true ? $lang_field_error : '') .
                            Db::getInstance()->getMsgError();
                    }
                }
            }
        }

        if (isset($product->price_tex) && !isset($product->price_tin)) {
            $product->price = $product->price_tex;
        } elseif (isset($product->price_tin) && !isset($product->price_tex)) {
            $product->price = $product->price_tin;
            // If a tax is already included in price, withdraw it from price
            if ($product->tax_rate) {
                $product->price = (float) number_format($product->price / (1 + $product->tax_rate / 100), 6, '.', '');
            }
        } elseif (isset($product->price_tin, $product->price_tex)) {
            $product->price = $product->price_tex;
        }

        if (!Configuration::get('PS_USE_ECOTAX')) {
            $product->ecotax = 0;
        }

        if (!empty($product->category) && is_array($product->category)) {
            $product->id_category = []; // Reset default values array
            foreach ($product->category as $value) {
                if (is_numeric($value)) {
                    if (Category::categoryExists((int) $value)) {
                        $product->id_category[] = (int) $value;
                    } else {
                        $category_to_create = new Category();
                        $category_to_create->id = (int) $value;
                        $category_to_create->name = AdminImportController::createMultiLangField($value);
                        $category_to_create->active = true;
                        $category_to_create->id_parent = (int) Configuration::get('PS_HOME_CATEGORY'); // Default parent is home for unknown category to create
                        $category_link_rewrite = Tools::str2url($category_to_create->name[$default_language_id]);
                        $category_to_create->link_rewrite = AdminImportController::createMultiLangField($category_link_rewrite);
                        if (($field_error = $category_to_create->validateFields(UNFRIENDLY_ERROR, true)) === true
                            && ($lang_field_error = $category_to_create->validateFieldsLang(UNFRIENDLY_ERROR, true)) === true
                            && !$validateOnly  // Do not move this condition: previous tests should be played always, but next ->add() test should not be played in validateOnly mode
                            && $category_to_create->add()) {
                            $product->id_category[] = (int) $category_to_create->id;
                        } else {
                            if (!$validateOnly) {
                                $this->errors[] = sprintf(
                                    $this->trans('%1$s (ID: %2$s) cannot be saved', [], 'Admin.Advparameters.Notification'),
                                    Tools::htmlentitiesUTF8($category_to_create->name[$default_language_id]),
                                    !empty($category_to_create->id) ? Tools::htmlentitiesUTF8($category_to_create->id) : 'null'
                                );
                            }
                            if ($field_error !== true || isset($lang_field_error) && $lang_field_error !== true) {
                                $this->errors[] = ($field_error !== true ? $field_error : '') . (isset($lang_field_error) && $lang_field_error !== true ? $lang_field_error : '') .
                                    Db::getInstance()->getMsgError();
                            }
                        }
                    }
                } elseif (!$validateOnly && is_string($value) && !empty($value)) {
                    $category = Category::searchByPath($default_language_id, trim($value), $this, 'productImportCreateCat');
                    if ($category['id_category']) {
                        $product->id_category[] = (int) $category['id_category'];
                    } else {
                        $this->errors[] = $this->trans(
                            '%data% cannot be saved',
                            [
                                '%data%' => Tools::htmlentitiesUTF8(trim($value)),
                            ],
                            'Admin.Advparameters.Notification'
                        );
                    }
                }
            }

            $product->id_category = array_values(array_unique($product->id_category));
        }

        // Category default now takes the value of the first new category during import
        if (isset($product->id_category[0])) {
            if (empty($product->id_category_default) || !in_array($product->id_category_default, $product->id_category)) {
                $product->id_category_default = (int) $product->id_category[0];
            }
        } else {
            if (empty($product->id_category_default)) {
                $defaultProductShop = new Shop($product->id_shop_default);
                $product->id_category_default = Category::getRootCategory(null, Validate::isLoadedObject($defaultProductShop) ? $defaultProductShop : null)->id;
            }
        }

        $link_rewrite = (is_array($product->link_rewrite) && isset($product->link_rewrite[$id_lang])) ? trim($product->link_rewrite[$id_lang]) : '';
        $valid_link = Validate::isLinkRewrite($link_rewrite);
        if ((isset($product->link_rewrite[$id_lang]) && empty($product->link_rewrite[$id_lang])) || !$valid_link) {
            $link_rewrite = Tools::str2url($product->name[$id_lang]);
            if ($link_rewrite == '') {
                $link_rewrite = 'friendly-url-autogeneration-failed';
            }
        }

        if (!$valid_link) {
            $this->informations[] = $this->trans(
                'Rewrite link for %1$s (ID %2$s): re-written as %3$s.',
                [
                    '%1$s' => Tools::htmlentitiesUTF8($product->name[$id_lang]),
                    '%2$s' => !empty($info['id']) ? Tools::htmlentitiesUTF8($info['id']) : 'null',
                    '%3$s' => Tools::htmlentitiesUTF8($link_rewrite),
                ],
                'Admin.Advparameters.Notification'
            );
        }

        if (!$valid_link || !(is_array($product->link_rewrite) && count($product->link_rewrite))) {
            $product->link_rewrite = AdminImportController::createMultiLangField($link_rewrite);
        } else {
            $product->link_rewrite[(int) $id_lang] = $link_rewrite;
        }

        // Convert comma into dot for all floating values
        foreach (Product::$definition['fields'] as $key => $array) {
            if ($array['type'] == Product::TYPE_FLOAT) {
                $product->{$key} = str_replace(',', '.', $product->{$key});
            }
        }

        // Indexation is already 0 if it's a new product, but not if it's an update
        $product->indexed = false;
        $productExistsInDatabase = false;

        if ($product->id && Product::existsInDatabase((int) $product->id, 'product')) {
            $productExistsInDatabase = true;
        }

        if (($match_ref && $product->reference && $product->existsRefInDatabase($product->reference)) || $productExistsInDatabase) {
            $product->date_upd = date('Y-m-d H:i:s');
        }

        $res = false;
        $field_error = $product->validateFields(UNFRIENDLY_ERROR, true);
        $lang_field_error = $product->validateFieldsLang(UNFRIENDLY_ERROR, true);
        if ($field_error === true && $lang_field_error === true) {
            // check quantity
            if ($product->quantity == null) {
                $product->quantity = 0;
            }

            // If match ref is specified && ref product && ref product already in base, trying to update
            if ($match_ref && $product->reference && $product->existsRefInDatabase($product->reference)) {
                $datas = Db::getInstance()->getRow('
                                        SELECT product_shop.`date_add`, p.`id_product`
                                        FROM `' . _DB_PREFIX_ . 'product` p
                                        ' . Shop::addSqlAssociation('product', 'p') . '
                                        WHERE p.`reference` = "' . pSQL($product->reference) . '"
                                ', false);
                $product->id = (int) $datas['id_product'];
                $product->date_add = pSQL($datas['date_add']);
                $res = ($validateOnly || $product->update());
            } // Else If id product && id product already in base, trying to update
            elseif ($productExistsInDatabase) {
                $datas = Db::getInstance()->getRow('
                                        SELECT product_shop.`date_add`
                                        FROM `' . _DB_PREFIX_ . 'product` p
                                        ' . Shop::addSqlAssociation('product', 'p') . '
                                        WHERE p.`id_product` = ' . (int) $product->id, false);
                $product->date_add = pSQL($datas['date_add']);
                $res = ($validateOnly || $product->update());
            }
            // If no id_product or update failed
            $product->force_id = (bool) $force_ids;

            if (!$res) {
                if ($product->date_add != '') {
                    $res = ($validateOnly || $product->add(false));
                } else {
                    $res = ($validateOnly || $product->add());
                }
            }

            if (!$validateOnly) {
                if ($product->getType() == Product::PTYPE_VIRTUAL) {
                    StockAvailable::setProductOutOfStock((int) $product->id, 1);
                } else {
                    StockAvailable::setProductOutOfStock((int) $product->id, (int) $product->out_of_stock);
                }

                if ($product_download_id = ProductDownload::getIdFromIdProduct((int) $product->id)) {
                    $product_download = new ProductDownload($product_download_id);
                    $product_download->delete(true);
                }

                if ($product->getType() == Product::PTYPE_VIRTUAL) {
                    $product_download = new ProductDownload();
                    $product_download->filename = ProductDownload::getNewFilename();
                    Tools::copy($info['file_url'], _PS_DOWNLOAD_DIR_ . $product_download->filename);
                    $product_download->id_product = (int) $product->id;
                    $product_download->nb_downloadable = (int) $info['nb_downloadable'];
                    $product_download->date_expiration = $info['date_expiration'];
                    $product_download->nb_days_accessible = (int) $info['nb_days_accessible'];
                    $product_download->display_filename = basename($info['file_url']);
                    $product_download->add();
                }
            }
        }

        $shops = [];
        $product_shop = explode($this->multiple_value_separator, $product->shop);
        foreach ($product_shop as $shop) {
            if (empty($shop)) {
                continue;
            }
            $shop = trim($shop);
            if (!empty($shop) && !is_numeric($shop)) {
                $shop = Shop::getIdByName($shop);
            }

            if (in_array($shop, $shop_ids)) {
                $shops[] = $shop;
            } else {
                $this->addProductWarning(Tools::safeOutput($info['name']), $product->id, $this->trans('Shop is not valid', [], 'Admin.Advparameters.Notification'));
            }
        }
        if (empty($shops)) {
            $shops = Shop::getContextListShopID();
        }
        // If both failed, mysql error
        if (!$res) {
            $this->errors[] = sprintf(
                $this->trans('%1$s (ID: %2$s) cannot be saved', [], 'Admin.Advparameters.Notification'),
                !empty($info['name']) ? Tools::safeOutput($info['name']) : 'No Name',
                !empty($info['id']) ? Tools::safeOutput($info['id']) : 'No ID'
            );
            $this->errors[] = ($field_error !== true ? $field_error : '') . ($lang_field_error !== true ? $lang_field_error : '') .
                Db::getInstance()->getMsgError();
        } else {
            // Product supplier
            if (!$validateOnly && !empty($product->id) && property_exists($product, 'supplier_reference') && !empty($product->id_supplier)) {
                $id_product_supplier = (int) ProductSupplier::getIdByProductAndSupplier((int) $product->id, 0, (int) $product->id_supplier);
                if ($id_product_supplier) {
                    $product_supplier = new ProductSupplier($id_product_supplier);
                } else {
                    $product_supplier = new ProductSupplier();
                }
                $product_supplier->id_product = (int) $product->id;
                $product_supplier->id_product_attribute = 0;
                $product_supplier->id_supplier = (int) $product->id_supplier;
                $product_supplier->product_supplier_price_te = $product->wholesale_price;
                $product_supplier->product_supplier_reference = $product->supplier_reference;
                $product_supplier->id_currency = Currency::getDefaultCurrency()->id;
                $product_supplier->save();
            }

            // SpecificPrice (only the basic reduction feature is supported by the import)
            if (!$shop_is_feature_active) {
                $info['shop'] = 1;
            } elseif (!isset($info['shop']) || empty($info['shop'])) {
                $info['shop'] = implode($this->multiple_value_separator, Shop::getContextListShopID());
            }

            // Get shops for each attributes
            $info['shop'] = explode($this->multiple_value_separator, $info['shop']);

            $id_shop_list = [];
            foreach ($info['shop'] as $shop) {
                if (!empty($shop) && !is_numeric($shop)) {
                    $id_shop_list[] = (int) Shop::getIdByName($shop);
                } elseif (!empty($shop)) {
                    $id_shop_list[] = $shop;
                }
            }

            if ((isset($info['reduction_price']) && $info['reduction_price'] > 0) || (isset($info['reduction_percent']) && $info['reduction_percent'] > 0)) {
                foreach ($id_shop_list as $id_shop) {
                    $specific_price = SpecificPrice::getSpecificPrice($product->id, $id_shop, 0, 0, 0, 1, 0, 0, 0, 0);

                    if (is_array($specific_price) && isset($specific_price['id_specific_price'])) {
                        $specific_price = new SpecificPrice((int) $specific_price['id_specific_price']);
                    } else {
                        $specific_price = new SpecificPrice();
                    }
                    $specific_price->id_product = (int) $product->id;
                    $specific_price->id_specific_price_rule = 0;
                    $specific_price->id_shop = $id_shop;
                    $specific_price->id_currency = 0;
                    $specific_price->id_country = 0;
                    $specific_price->id_group = 0;
                    $specific_price->price = -1;
                    $specific_price->id_customer = 0;
                    $specific_price->from_quantity = 1;
                    $specific_price->reduction = (isset($info['reduction_price']) && $info['reduction_price']) ? $info['reduction_price'] : $info['reduction_percent'] / 100;
                    $specific_price->reduction_type = (isset($info['reduction_price']) && $info['reduction_price']) ? 'amount' : 'percentage';
                    $specific_price->from = (isset($info['reduction_from']) && Validate::isDate($info['reduction_from'])) ? $info['reduction_from'] : '0000-00-00 00:00:00';
                    $specific_price->to = (isset($info['reduction_to']) && Validate::isDate($info['reduction_to'])) ? $info['reduction_to'] : '0000-00-00 00:00:00';
                    if (!$validateOnly && !$specific_price->save()) {
                        $this->addProductWarning(Tools::safeOutput($info['name']), $product->id, $this->trans('Discount is invalid', [], 'Admin.Advparameters.Notification'));
                    }
                }
            }

            if (!$validateOnly && !empty($product->tags)) {
                if (isset($product->id) && $product->id) {
                    $tags = Tag::getProductTags($product->id);
                    if (is_array($tags) && count($tags)) {
                        /** @phpstan-ignore-next-line $product->tags is filled with a string at line 1986 */
                        $productTags = explode($this->multiple_value_separator, $product->tags);
                        foreach ($productTags as $key => $tag) {
                            if (!empty($tag)) {
                                $productTags[$key] = trim($tag);
                            }
                        }
                        $tags[$id_lang] = $productTags;
                        $product->tags = $tags;
                    }
                }
                // Delete tags for this id product, for no duplicating error
                Tag::deleteTagsForProduct($product->id);
                if (!is_array($product->tags) && !empty($product->tags)) {
                    $product->tags = AdminImportController::createMultiLangField($product->tags);
                    foreach ($product->tags as $key => $tags) {
                        $is_tag_added = Tag::addTags($key, $product->id, $tags, $this->multiple_value_separator);
                        if (!$is_tag_added) {
                            $this->addProductWarning(Tools::safeOutput($info['name']), $product->id, $this->trans('Tags list is invalid', [], 'Admin.Advparameters.Notification'));

                            break;
                        }
                    }
                } else {
                    foreach ($product->tags as $key => $tags) {
                        $str = '';
                        foreach ($tags as $one_tag) {
                            $str .= $one_tag . $this->multiple_value_separator;
                        }
                        $str = rtrim($str, $this->multiple_value_separator);

                        $is_tag_added = Tag::addTags($key, $product->id, $str, $this->multiple_value_separator);
                        if (!$is_tag_added) {
                            $this->addProductWarning(Tools::safeOutput($info['name']), (int) $product->id, $this->trans(
                                'Invalid tag(s) (%s)',
                                [$str],
                                'Admin.Notifications.Error'
                            ));

                            break;
                        }
                    }
                }
            }

            // delete existing images if "delete_existing_images" is set to 1
            if (!$validateOnly && isset($product->delete_existing_images)) {
                if ((bool) $product->delete_existing_images) {
                    $product->deleteImages();
                }
            }

            if (!$validateOnly && isset($product->image) && is_array($product->image) && count($product->image)) {
                $product_has_images = (bool) Image::getImages($this->context->language->id, (int) $product->id);
                foreach ($product->image as $key => $url) {
                    $url = trim($url);
                    $error = false;
                    if (!empty($url)) {
                        $url = str_replace(' ', '%20', $url);

                        $image = new Image();
                        $image->id_product = (int) $product->id;
                        $image->position = Image::getHighestPosition($product->id) + 1;
                        $image->cover = (!$key && !$product_has_images) ? true : false;
                        $alt = $product->image_alt[$key];
                        if (strlen($alt) > 0) {
                            $image->legend = self::createMultiLangField($alt);
                        }
                        // file_exists doesn't work with HTTP protocol
                        if (($field_error = $image->validateFields(UNFRIENDLY_ERROR, true)) === true
                            && ($lang_field_error = $image->validateFieldsLang(UNFRIENDLY_ERROR, true)) === true && $image->add()) {
                            // associate image to selected shops
                            $image->associateTo($shops);
                            if (!AdminImportController::copyImg($product->id, $image->id, $url, 'products', !$regenerate)) {
                                $image->delete();
                                $this->warnings[] = $this->trans('Error copying image: %url%', ['%url%' => $url], 'Admin.Advparameters.Notification');
                            }
                        } else {
                            $error = true;
                        }
                    } else {
                        $error = true;
                    }

                    if ($error) {
                        $this->warnings[] = $this->trans(
                            'Product #%id%: the picture (%url%) cannot be saved.', [
                                '%id%' => Tools::htmlentitiesUTF8(isset($image) ? $image->id_product : ''),
                                '%url%' => Tools::htmlentitiesUTF8($url),
                            ],
                            'Admin.Advparameters.Notification'
                        );
                    }
                }
            }

            if (!$validateOnly && isset($product->id_category) && is_array($product->id_category)) {
                $product->updateCategories(array_map('intval', $product->id_category));
            }

            $product->checkDefaultAttributes();
            if (!$validateOnly && !$product->cache_default_attribute) {
                Product::updateDefaultAttribute($product->id);
            }

            // Features import
            $features = get_object_vars($product);

            if (!$validateOnly && isset($features['features']) && !empty($features['features'])) {
                foreach (explode($this->multiple_value_separator, $features['features']) as $single_feature) {
                    if (empty($single_feature)) {
                        continue;
                    }
                    $tab_feature = explode(':', $single_feature);
                    $feature_name = isset($tab_feature[0]) ? trim($tab_feature[0]) : '';
                    $feature_value = isset($tab_feature[1]) ? trim($tab_feature[1]) : '';
                    $position = isset($tab_feature[2]) ? (int) $tab_feature[2] - 1 : false;
                    $custom = isset($tab_feature[3]) ? (int) $tab_feature[3] : false;
                    if (!empty($feature_name) && !empty($feature_value)) {
                        $id_feature = (int) Feature::addFeatureImport($feature_name, $position);
                        $id_product = null;
                        if ($force_ids || $match_ref) {
                            $id_product = (int) $product->id;
                        }
                        $id_feature_value = (int) FeatureValue::addFeatureValueImport($id_feature, $feature_value, $id_product, $id_lang, $custom);
                        Product::addFeatureProductImport($product->id, $id_feature, $id_feature_value);
                    }
                }
            }
            // clean feature positions to avoid conflict
            Feature::cleanPositions();

            if (!$validateOnly) {
                if ($shop_is_feature_active) {
                    foreach ($shops as $shop) {
                        StockAvailable::setQuantity((int) $product->id, 0, (int) $product->quantity, (int) $shop);
                        if (strlen($product->location) > 0) {
                            StockAvailable::setLocation((int) $product->id, pSQL($product->location), (int) $shop);
                        }
                    }
                } else {
                    StockAvailable::setQuantity((int) $product->id, 0, (int) $product->quantity, (int) $this->context->shop->id);
                    if (strlen($product->location) > 0) {
                        StockAvailable::setLocation((int) $product->id, pSQL($product->location), (int) $this->context->shop->id);
                    }
                }
            }

            // Accessories linkage
            if (isset($product->accessories) && !$validateOnly && is_array($product->accessories) && count($product->accessories)) {
                $accessories[$product->id] = $product->accessories;
            }
        }
    }
}
