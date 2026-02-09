# rex_yform_value_tabs

Ersetzt die Datei `lib/yform/value/tabs.php` im Repo [FriendsOfRedaxo/yform_field](https://github.com/FriendsOfREDAXO/yform_field/tree/main)

Bitte einfach die Datei aus diesem Repo in die lokale Installation des Addons an die angegebene Stelle kopieren.

## Gewünschtes Verhalten

Wenn für Felder innerhalb eines Tabs die Validierung anschlägt, wird das Feld markiert und zusätzlich der Tab rot markiert 
und auch aufgeblendet. Zumindest bei "normalen" Feldtypen und Einsatz von YForm-Validatoren funktioniert das auch.

## Problem

Es gibt auch Felder, die Validierungen in der Methode `enterObject` selbst durchführen.
Aber `enterObject` wird generell erst nach Abschluß der Validierungen ausgeführt und die `enterObject` des Tab-Values,
die die Validerungsergebnisse abfragt, liegt zeitlich vor der des Values, kann dessen Ergebnisse also nicht verwerten.

Das gewünschtes Verhalten tritt also nur bei einfachen Feldern wie
Text oder Choice ein, aber definitv nicht bei `be_manager_relation` und Sub-Formularen.

## Ursache und Lösung

Die Ursache ist die Reihenfolge der Formularbearbeitung. Die Lösung ist: Ändern der Reihenfolge im Tab-Value.
Nicht mehr das einzelne Tab-Element baut sein HTML zusammen, sondern das letzte Tab-Value, das die Tab-Gruppe abschließt,
übernimmt die Aufgabe für alle.

Zu diesem Zeitpunkt sind auch bei den innen liegenden Values mit ihren `enterObject` durch.
So werden auch Fehler aus z.B. Sub-Formularen berücksichtigt und sichtbar gemacht.

## Warum kein PR für yform_field?

Da müssen m.E. noch ein paar organisatorische Fragen geklärt werden. (siehe Issue [Wie geht es hier weiter?](https://github.com/FriendsOfREDAXO/yform_field/issues/2)).
