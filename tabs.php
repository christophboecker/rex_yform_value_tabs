<?php

/**
 * Tabs in YForm-Formularen.
 *
 * Es müssen mindestens drei Tab-Values derselben Gruppe (group_by) im Formular sein:
 *  erster Tab      -> beginnt einen Tab und baut das Tab-Menü über alle auf
 *  innerer Tab     -> jeder innere Tab schließt den vorhergehenden ab und öffnet den eigenen Container
 *  letzter Tab     -> ohne eigenen Eintrag im Tab-Menü, schließt den vorhergehenden Container und die Gruppe
 *
 * nur ein Tab-Value im Formular:   es fehlt der schließende Tab. Technisch nicht machbar.
 * nur zwei Tab-Values im Formular: es gibt eh nur einen Tab im Menü. Das ist sinnlos.
 *
 * Nutzt in rex_yform_field die Felder ...
 *  - group-by: Clusterung als Tab-Gruppe
 *  - default:  Beim Anzeigen ausgewählter Default-Tab (1= Der erste / 2 = der ausgewählte)
 *
 * Wenn in einem Tab ein Feld mit Fehlermeldung steckt, wird der Tab optisch markiert
 * und aktiviert, unabhängig von den Einstellungen in "default".
 *
 * Wurde das Formulat mit "Übernehmen" gespeichert wird der zuletzt aktive Tab wieder aktiv
 * gesetzt. Ausnahme: in einem anderen Tab ist ein Feld mit Fehlermeldung.
 */

class rex_yform_value_tabs extends rex_yform_value_abstract
{
    /**
     * Variablen zur Ablage der Tab-Menü-Struktur.
     * @var array<self>
     */
    public array $tabset = [];
    public int $sequence = -1;
    public bool $selected = false;
    public string $hasErrorField = '';
    public rex_yform_value_tabs $nextTab;

    public string $fragment = 'value.tabs.tpl.php';

    /**
     * sucht die Elemente der eigenen Tabgruppe (group_by) zusammen und markiert
     * alle Elemnte konsolidiert.
     * Wird nur beim ersten Element der Tabgruppe ausgeführt für alle.
     */
    protected function collectTabElements(): void
    {
        // nur ausführen, wenn noch nicht durch ein anderes Tab derselben Gruppe erledigt
        if (0 < count($this->tabset)) {
            return;
        }

        // Alle Tab-Elemente derselben Gruppe ermitteln
        /** @var array<rex_yform_value_abstract> $tabElements */
        $tabElements = $this->params['values'];
        /** @var array<self> $tabElements */
        $tabElements = array_filter($tabElements, function ($v) {
            return is_a($v, self::class) && $v->getElement('group_by') === $this->getElement('group_by');
        });

        // Zu wenig Elemente: dann wird das nix, ignorieren
        if (3 > count($tabElements)) {
            return;
        }

        // Der letzte Tab (nur Platzhalter für den Abschluss der Tabgruppe) ist nie aktiv.
        $tabElements[array_key_last($tabElements)]->setElement('default', '1');

        // Zuletzt aktivierten Tab zwecks Wiederaktivierung aus dem REQUEST holen
        $active = rex_request::request(md5($this->getFieldName()), 'int', -1);

        // In den Tabs die steuernden Informationen eintragen
        $i = 0;
        $prev = null;
        foreach ($tabElements as $id => $tab) {
            $tab->tabset = $tabElements;
            $tab->sequence = $i++;
            $tab->selected = false;
            if (-1 === $active && '2' === $tab->getElement('default')) {
                $active = $id;
            }
            if (null !== $prev) {
                $prev->nextTab = $tab;
            }
            $prev = $tab;
        }
        $active = -1 === $active ? array_key_first($tabElements) : $active;
        // Das letzte Element erhält die Nummer PHP_INT_MAX;
        $tabElements[array_key_last($tabElements)]->sequence = PHP_INT_MAX;

        $tabElements[$active]->selected = true;
    }

    /**
     * Prüft ab, ob das angegebene Value-Feld innerhalb des Feldbereich dieses
     * Tabs liegt und ob es als fehlerhaft validiert wurde.
     */
    protected function isInnerValueTabWithError(rex_yform_value_abstract $value): bool
    {
        $valueId = $value->getId();
        return $valueId > $this->getId()
               && $valueId < $this->nextTab->getId()
               && isset($this->params['warning'][$valueId]);
    }

    /**
     * Durchläuft die Value-Elemente des Formulars und ermittelt, ob sie
     * innerhalb des Tab liegen und Fehler aufweisen.
     */
    protected function checkError(): void
    {
        // Gibt es Fehlermeldungen in einem Tab-Bereich? Dann den Tab markieren
        /** @var array<rex_yform_value_abstract> $valueElements */
        $valueElements = $this->params['values'];
        $valueElements = array_filter(
            $valueElements,
            $this->isInnerValueTabWithError(...),
        );
        if (0 === count($valueElements)) {
            return;
        }

        $this->hasErrorField = $this->params['error_class'];
    }

    /**
     * Zu diesem Zeitpunkt sind alle Felder existent.
     *
     * Startet im Nachgang der "enterObject" aller im Tabset vorkommenden Felder,
     * da u.U. feldspezifische fehlermeldungen nicht in der Validierung, sondern
     * erst im "enterObject" des Values erfolgt sein könnten.
     *
     * @return void
     */
    public function enterObject()
    {
        if (!$this->needsOutput()) {
            return;
        }

        $this->collectTabElements();

        // erst beim schließenden Tab für alle vorhergehenden tätig werden.
        // Ausgabefeld blanko vorbelegen, damit der später generieret Output an der richtigen Stelle steht.
        if ($this->sequence < PHP_INT_MAX) {
            $this->params['form_output'][$this->getId()] = '';
            return;
        }

        /**
         * Je Tabset vor dem abschließenden wird ermittelt, ob ein
         * FehlerValue darin vorkommt.
         * Außerdem die Tab-ID des kleinsten (ersten) Tab mit FehlerValue
         * ermitteln.
         */
        $errorTabId = PHP_INT_MAX;
        foreach ($this->tabset as $tab) {
            if ($tab === $this) {
                continue;
            }
            $tab->checkError();
            if ('' < $tab->hasErrorField) {
                $errorTabId = min($errorTabId, $tab->getId());
            }
        }

        /**
         * Wenn es einen FehlerTab gibt: diesen "active" setzen und einen zuvor
         * schon als Active markierten Tab neutralisieren.
         */
        if (PHP_INT_MAX !== $errorTabId) {
            foreach ($this->tabset as $id => $tab) {
                $tab->selected = $id === $errorTabId;
            }
        }

        /**
         * Die Tabs durchlaufen und das HTML zusammenbauen.
         */
        foreach ($this->tabset as $id => $tab) {
 
            $output = '';

            // Wenn erstes Tab der Gruppe: Menü aufbauen, Tab-Container öffnen
            if (0 === $tab->sequence) {
                $output .= $tab->parse($tab->fragment, ['option' => 'open_tabset', 'tabset' => $tab->tabset]);
                $startFeld = true;
            }

            // Wenn nicht Startfeld: vorhergehende Tab-Gruppe schließen
            if (0 < $tab->sequence) {
                $output .= $tab->parse($tab->fragment, ['option' => 'close_tab']);
            }

            // Wenn nicht letzter Eintrag: tab-Gruppe öffnen; der letzte dient ja nur dem Abschluß
            if (PHP_INT_MAX > $tab->sequence) {
                $output .= $tab->parse($tab->fragment, ['option' => 'open_tab']);
            }

            // Wenn letzter Eintrag: Tab-Container schließen
            if (PHP_INT_MAX === $tab->sequence) {
                $output .= $tab->parse($tab->fragment, ['option' => 'close_tabset']);
            }

            // wenn (-1 === $this->sequence) => nix tun

            $this->params['form_output'][$tab->getId()] = $output;
        }
    }

    public function getDescription(): string
    {
        return 'tabs|name|label|aktiv[1,2]|[tabgroup]';
    }

    /**
     * @return array<string,mixed>
     */
    public function getDefinitions(): array
    {
        return [
            'type' => 'value',
            'name' => 'tabs',
            'values' => [
                'name' => [
                    'type' => 'name',
                    'label' => rex_i18n::msg('yform_values_defaults_name'),
                ],
                'label' => [
                    'type' => 'text',
                    'label' => rex_i18n::msg('yform_values_defaults_label'),
                ],
                'default' => [
                    'type' => 'choice',
                    'label' => rex_i18n::msg('yform_values_tabs_active_label'),
                    'choices' => rex_i18n::rawMsg('yform_values_tabs_active_options'),
                    'expanded' => '1',
                    'default' => '1',
                    'notice' => rex_i18n::msg('yform_values_tabs_active_notice'),
                ],
                'group_by' => [
                    'type' => 'text',
                    'label' => rex_i18n::msg('yform_values_tabs_cluster_label'),
                    'notice' => rex_i18n::msg('yform_values_tabs_cluster'),
                ],
            ],
            'validates' => [
                ['customfunction' => ['name' => 'prio', 'function' => [$this, 'validateTabOrder']]],
            ],
            'description' => rex_i18n::msg('yform_values_tabs_description'),
            'dbtype' => 'none',
            'is_searchable' => false,
            'is_hiddeninlist' => true,
        ];
    }

    /**
     * Callback für customvalidator auf 'prio'.
     *
     * Hintergrund: Tabs können in Gruppen zusammengefasst werden, was notwendig ist,
     * um mehrere Tabsets in ein Formular zu bekommen. Die Tabs zweier Gruppen dürfen
     * nur aufeinander folgen, nicht aber überlappen.
     *
     * Beim Speichern wird überprüft, ob die in prio ausgewählte Position innerhalb einer
     * anderen Tab-Gruppe liegt
     *
     * Die Parameter sind so belegt:
     *  - Array mit dem Feldnamen ()
     *  - Array mit den aktuellen Werten für 'url' und 'subdomain'
     *  - Rückgabewert als Vorbelegung (sollte leer sein), ignorieren
     *  - Instanz der aktiven Validator-Klasse
     *  - Array mit den Instanzen der Felder ('url', 'subdomain')
     *
     * @api
     * @param list<string> $field   Feldname (hier 'prio')
     * @param int $prio
     * @param string $return
     * @param rex_yform_validate_customfunction $self
     * @param array<string,rex_yform_value_prio> $elements
     */
    public static function validateTabOrder($field, $prio, $return, $self, $elements): bool
    {
        /**
         * Problem: die Spalte rex_yform_field.group_by ist womöglich nicht vorhanden; sie wird
         * erst angelegt, wenn es das Feld gespeichert ist und tatsächlich einen gefüllten(!)
         * Gruppen-Namen hat (leer gilt nicht). Wenn es keine Spalte group_by gibt, gibt es auch
         * noch keine Gruppen und damit keine Notwendigkeit der Überprüfung.
         */
        $sql = rex_sql::factory();
        $columns = $sql::showColumns(rex::getTable('yform_field'));
        $groupByColumn = array_filter($columns, static function ($v) {
            return 'group_by' === $v['name'];
        });
        if (0 === count($groupByColumn)) {
            return false;
        }

        $table = $self->params['main_table'];
        $tablename = '';
        $myGroup = '';
        /** @var rex_yform_value_abstract $valueField */
        foreach ($self->params['values'] as $id => $valueField) {
            if ('group_by' === $valueField->getName()) {
                $myGroup = $valueField->getValue();
                continue;
            }
            if ('table_name' === $valueField->getName()) {
                $tablename = $valueField->getValue();
                continue;
            }
        }

        /**
         * Die Tab-Felder derselben Tabelle aus rex_yform_field abrufen.
         */
        $sql = rex_sql::factory();
        $query = 'SELECT `id`,`prio`,`label`,`name`,`group_by` FROM ' . $table . ' WHERE `table_name`=:tablename AND `type_name`=:typename AND `id`!=:id AND `group_by`!=:group ORDER BY `group_by`,`prio` ASC';
        $tabs = $sql->getArray(
            'SELECT `id`,`prio`,`label`,`name`,`group_by` FROM ' . $table . ' WHERE `table_name`=:tablename AND `type_name`=:typename AND `id`!=:id AND `group_by`!=:group ORDER BY `group_by`,`prio` ASC',
            [
                ':tablename' => $tablename,
                ':typename' => substr(self::class, 16),
                ':id' => $self->params['main_id'],
                ':group' => $myGroup,
            ],
        );

        /**
         * Die Gruppen bilden. Dabei den Prio-Wert für alle Felder ab
         * demjenigen, dessen Position dieses Feld einnehmen soll ($prio),
         * um 1 heraufsetzen.
         */
        $groups = [];
        foreach ($tabs as $tab) {
            if ($prio <= $tab['prio']) {
                ++$tab['prio'];
            }
            $groups[$tab['group_by']][$tab['prio']] = $tab;
        }

        /**
         * Überlappung mit einer der Gruppen ermitteln.
         * Wenn gefunden: den Fehlertext zusammenbauen und
         * mit true abbrechen.
         */

        foreach ($groups as $group) {
            $start = current($group);
            $end = end($group);
            if ($prio > $start['prio'] && $prio < $end['prio']) {
                $error = rex_i18n::msg(
                    'yform_values_tabs_setup_prio_error',
                    $elements[$field]->getLabel(),
                    $start['group_by'],
                    $start['label'],
                    $start['name'],
                );
                $self->setElement('message', $error);
                return true;
            }
        }
        return false;
    }
}
