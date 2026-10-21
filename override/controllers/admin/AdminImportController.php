<?php

/**
 * Permette di importare prodotti senza la colonna "Nome".
 *
 * Il core (9.0.x / 9.1.x) blocca l'import solo dentro productImportOne(): se il prodotto e' nuovo
 * e $info['name'] e' vuoto restituisce un errore. Il campo Product::$name non e' "required".
 * Invece di copiare l'intero metodo del core, ne compiliamo il nome prima di delegare al parent:
 * gli aggiornamenti di PrestaShop (e le sue correzioni di sicurezza) restano quindi attivi.
 *
 *  - prodotto NUOVO senza nome       -> "n.d." (e link_rewrite dal riferimento, se presente)
 *  - prodotto ESISTENTE senza nome   -> mantiene il nome attuale
 */
class AdminImportController extends AdminImportControllerCore
{
    public const MISSING_PRODUCT_NAME = 'n.d.';

    protected function productImportOne($info, $default_language_id, $id_lang, $force_ids, $regenerate, $shop_is_feature_active, $shop_ids, $match_ref, &$accessories, $validateOnly = false)
    {
        if (empty($info['name'])) {
            $info = $this->completeMissingProductName($info, (int) $id_lang, (bool) $force_ids, (bool) $match_ref);
        }

        parent::productImportOne($info, $default_language_id, $id_lang, $force_ids, $regenerate, $shop_is_feature_active, $shop_ids, $match_ref, $accessories, $validateOnly);
    }

    /**
     * @param array<string, mixed> $info
     *
     * @return array<string, mixed>
     */
    protected function completeMissingProductName(array $info, $id_lang, $force_ids, $match_ref)
    {
        $id_product = $this->findExistingProductId($info, $force_ids, $match_ref);

        if ($id_product) {
            // Stesso nome gia' presente: l'import lo riscrive invariato.
            $name = Db::getInstance()->getValue(
                'SELECT `name` FROM `' . _DB_PREFIX_ . 'product_lang`
                WHERE `id_product` = ' . (int) $id_product . ' AND `id_lang` = ' . (int) $id_lang,
                false
            );
            $info['name'] = ($name !== false && $name !== '') ? $name : self::MISSING_PRODUCT_NAME;

            return $info;
        }

        $info['name'] = self::MISSING_PRODUCT_NAME;

        // Evita che tutti i nuovi prodotti ricevano l'URL "n-d".
        if (empty($info['link_rewrite']) && !empty($info['reference'])) {
            $link_rewrite = Tools::str2url($info['reference']);
            if ($link_rewrite !== '') {
                $info['link_rewrite'] = $link_rewrite;
            }
        }

        return $info;
    }

    /**
     * Stessa logica di ricerca del core in productImportOne().
     *
     * @param array<string, mixed> $info
     *
     * @return int 0 se il prodotto e' nuovo
     */
    protected function findExistingProductId(array $info, $force_ids, $match_ref)
    {
        if ($force_ids && !empty($info['id'])) {
            return Product::existsInDatabase((int) $info['id'], 'product') ? (int) $info['id'] : 0;
        }

        if ($match_ref && isset($info['reference'])) {
            return (int) Db::getInstance()->getValue(
                'SELECT p.`id_product`
                FROM `' . _DB_PREFIX_ . 'product` p
                ' . Shop::addSqlAssociation('product', 'p') . '
                WHERE p.`reference` = "' . pSQL($info['reference']) . '"',
                false
            );
        }

        return 0;
    }
}
