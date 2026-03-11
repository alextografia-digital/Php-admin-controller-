<?php

class Product extends ProductCore
{
    /**
     * Durante l'importazione CSV, assicuriamo che il nome sia gestito correttamente
     * anche se la colonna non è mappata.
     */
    public function __construct($id_product = null, $full = false, $id_lang = null, $id_shop = null, ?Context $context = null)
    {
        parent::__construct($id_product, $full, $id_lang, $id_shop, $context);

        // Se siamo in fase di importazione e il prodotto è nuovo, impostiamo un nome provvisorio
        if (defined('PS_MASS_PRODUCT_CREATION') && PS_MASS_PRODUCT_CREATION) {
            if (!$this->id) {
                $this->name = [];
                foreach (Language::getIDs(false) as $id_lang_id) {
                    $this->name[$id_lang_id] = 'n.d.';
                }
            }
        }
    }

    /**
     * Override di validateFieldsLang per gestire i nomi mancanti durante l'importazione.
     * Garantisce che i nuovi prodotti ricevano "n.d." e che i prodotti esistenti mantengano il proprio nome.
     */
    public function validateFieldsLang($die = true, $error_return = false)
    {
        // Controlliamo se siamo nel contesto di un'importazione CSV
        if (defined('PS_MASS_PRODUCT_CREATION') && PS_MASS_PRODUCT_CREATION) {
            $id_lang_default = (int)Configuration::get('PS_LANG_DEFAULT');

            // Se il nome è vuoto (colonna ignorata nel CSV), dobbiamo gestirlo
            if (empty($this->name) || (is_array($this->name) && empty($this->name[$id_lang_default]))) {
                if ($this->id) {
                    // PRODOTTO ESISTENTE: Recuperiamo il nome originale dal database
                    $sql = 'SELECT id_lang, name FROM ' . _DB_PREFIX_ . 'product_lang WHERE id_product = ' . (int)$this->id;
                    $db_names = Db::getInstance()->executeS($sql);

                    if ($db_names) {
                        if (!is_array($this->name)) {
                            $this->name = [];
                        }
                        foreach ($db_names as $row) {
                            // Ripristiniamo il nome originale solo se non è stato fornito un nuovo nome nel CSV
                            if (empty($this->name[$row['id_lang']])) {
                                $this->name[$row['id_lang']] = $row['name'];
                            }
                        }
                    }
                }

                // Se è un nuovo prodotto o il recupero dal DB è fallito, forziamo "n.d."
                if (empty($this->name[$id_lang_default])) {
                    if (!is_array($this->name)) {
                        $this->name = [];
                    }
                    $this->name[$id_lang_default] = 'n.d.';
                }
            }

            // Assicuriamoci che tutte le lingue abbiano un valore per superare la validazione standard
            if (is_array($this->name) && !empty($this->name[$id_lang_default])) {
                foreach (Language::getIDs(false) as $id_lang_id) {
                    if (empty($this->name[$id_lang_id])) {
                        $this->name[$id_lang_id] = $this->name[$id_lang_default];
                    }
                }
            }
        }

        return parent::validateFieldsLang($die, $error_return);
    }
}
