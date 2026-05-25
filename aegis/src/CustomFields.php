<?php
/**
 * CustomFields — helpers for the extensible metadata system.
 * Controllers call CustomFields::getDefinitions($entityType) to get the field
 * schema, CustomFields::getValues($entityType, $entityId) to fetch stored values,
 * and CustomFields::saveFromPost($entityType, $entityId) to persist submitted data.
 */
class CustomFields {

    public static function getDefinitions(string $entityType): array {
        return Database::fetchAll(
            "SELECT * FROM custom_field_definitions
             WHERE entity_type = ? AND is_active = TRUE ORDER BY sort_order, id",
            [$entityType]
        );
    }

    public static function getValues(string $entityType, int $entityId): array {
        $rows = Database::fetchAll(
            "SELECT cfd.name, cfd.field_type, cfv.value_text, cfv.value_number, cfv.value_date, cfv.value_json
             FROM custom_field_definitions cfd
             LEFT JOIN custom_field_values cfv
               ON cfv.definition_id = cfd.id
              AND cfv.entity_type = ? AND cfv.entity_id = ?
             WHERE cfd.entity_type = ? AND cfd.is_active = TRUE
             ORDER BY cfd.sort_order, cfd.id",
            [$entityType, $entityId, $entityType]
        );

        $values = [];
        foreach ($rows as $r) {
            $values[$r['name']] = self::extractValue($r);
        }
        return $values;
    }

    public static function saveFromPost(string $entityType, int $entityId): void {
        $definitions = self::getDefinitions($entityType);
        $userId      = Auth::id();

        foreach ($definitions as $def) {
            $postKey  = 'cf_' . $def['name'];
            $rawValue = $_POST[$postKey] ?? null;

            if ($rawValue === null || $rawValue === '') {
                if ($def['is_required']) continue; // skip empty required — validation should catch it
                // Clear existing value
                Database::query(
                    "DELETE FROM custom_field_values WHERE definition_id = ? AND entity_type = ? AND entity_id = ?",
                    [$def['id'], $entityType, $entityId]
                );
                continue;
            }

            [$col, $val] = self::prepareValue($def['field_type'], $rawValue);

            Database::query(
                "INSERT INTO custom_field_values (definition_id, entity_type, entity_id, {$col}, updated_by, updated_at)
                 VALUES (?, ?, ?, ?, ?, NOW())
                 ON CONFLICT (definition_id, entity_type, entity_id)
                 DO UPDATE SET {$col} = EXCLUDED.{$col}, updated_by = EXCLUDED.updated_by, updated_at = NOW()",
                [$def['id'], $entityType, $entityId, $val, $userId]
            );
        }
    }

    public static function validateFromPost(string $entityType): array {
        $errors      = [];
        $definitions = self::getDefinitions($entityType);
        foreach ($definitions as $def) {
            $val = $_POST['cf_' . $def['name']] ?? '';
            if ($def['is_required'] && ($val === '' || $val === null)) {
                $errors[] = "'{$def['label']}' is required.";
            }
            if ($val !== '' && $def['field_type'] === 'url') {
                $scheme = strtolower(parse_url($val, PHP_URL_SCHEME) ?? '');
                if (!in_array($scheme, ['http', 'https'])) {
                    $errors[] = "'{$def['label']}' must be a valid http/https URL.";
                }
            }
        }
        return $errors;
    }

    /**
     * Render HTML form fields for a set of definitions.
     */
    public static function renderFields(array $definitions, array $values = []): string {
        $html = '';
        foreach ($definitions as $def) {
            $name  = 'cf_' . $def['name'];
            $label = Security::h($def['label']);
            $req   = $def['is_required'] ? '<span class="required">*</span>' : '';
            $val   = $values[$def['name']] ?? '';
            $valH  = Security::h((string)$val);

            $input = match ($def['field_type']) {
                'textarea'    => "<textarea name=\"{$name}\" class=\"form-control\" rows=\"3\">{$valH}</textarea>",
                'number'      => "<input type=\"number\" name=\"{$name}\" class=\"form-control\" value=\"{$valH}\">",
                'date'        => "<input type=\"date\" name=\"{$name}\" class=\"form-control\" value=\"{$valH}\">",
                'boolean'     => "<label class=\"toggle-switch\"><input type=\"hidden\" name=\"{$name}\" value=\"0\"><input type=\"checkbox\" name=\"{$name}\" value=\"1\" " . ($val ? 'checked' : '') . "><span class=\"toggle-slider\"></span></label>",
                'url'         => "<input type=\"url\" name=\"{$name}\" class=\"form-control\" value=\"{$valH}\" placeholder=\"https://\">",
                'select'      => self::renderSelect($name, $def['options'], $val),
                'multiselect' => self::renderMultiselect($name, $def['options'], $val),
                default       => "<input type=\"text\" name=\"{$name}\" class=\"form-control\" value=\"{$valH}\">",
            };

            $html .= "<div class=\"form-group\"><label class=\"form-label\">{$label} {$req}</label>{$input}</div>\n";
        }
        return $html;
    }

    private static function renderSelect(string $name, ?string $optionsJson, mixed $selected): string {
        $options = json_decode($optionsJson ?? '[]', true) ?: [];
        $html = "<select name=\"{$name}\" class=\"form-control\"><option value=\"\">— Select —</option>";
        foreach ($options as $opt) {
            $sel  = $selected === $opt ? ' selected' : '';
            $optH = Security::h($opt);
            $html .= "<option value=\"{$optH}\"{$sel}>{$optH}</option>";
        }
        return $html . '</select>';
    }

    private static function renderMultiselect(string $name, ?string $optionsJson, mixed $selected): string {
        $options  = json_decode($optionsJson ?? '[]', true) ?: [];
        $selected = is_array($selected) ? $selected : (json_decode((string)$selected, true) ?: []);
        $html = "<select name=\"{$name}[]\" class=\"form-control\" multiple size=\"4\">";
        foreach ($options as $opt) {
            $sel  = in_array($opt, $selected) ? ' selected' : '';
            $optH = Security::h($opt);
            $html .= "<option value=\"{$optH}\"{$sel}>{$optH}</option>";
        }
        return $html . '</select>';
    }

    private static function prepareValue(string $type, mixed $raw): array {
        return match ($type) {
            'number'      => ['value_number', (float)$raw],
            'date'        => ['value_date',   Security::sanitizeInput((string)$raw)],
            'multiselect' => ['value_json',   json_encode((array)$raw)],
            'boolean'     => ['value_text',   $raw ? '1' : '0'],
            default       => ['value_text',   Security::sanitizeInput((string)$raw)],
        };
    }

    private static function extractValue(array $row): mixed {
        return match ($row['field_type']) {
            'number'      => $row['value_number'],
            'date'        => $row['value_date'],
            'multiselect' => json_decode($row['value_json'] ?? '[]', true),
            default       => $row['value_text'],
        };
    }
}
