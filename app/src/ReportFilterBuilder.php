<?php
// src/ReportFilterBuilder.php

class ReportFilterBuilder {
    private $filters = [];
    private $params = [];

    /**
     * Adds a filter condition to the builder.
     *
     * @param string $field The database field (e.g., 'table.column').
     * @param string $operator The SQL operator (e.g., '=', 'LIKE', 'BETWEEN', '>', '<').
     * @param mixed $value The value(s) for the filter.
     */
    public function addFilter($field, $operator, $value) {
        // Skip empty values but allow 0
        if ($value === '' || $value === null) {
            return;
        }

        switch (strtoupper($operator)) {
            case 'BETWEEN':
                // Expects an array or comma-separated string for ranges
                if (is_array($value) && count($value) >= 2) {
                    $val1 = $value[0];
                    $val2 = $value[1];
                } elseif (is_string($value) && strpos($value, ',') !== false) { // Simple CSV check
                     list($val1, $val2) = explode(',', $value, 2);
                } else {
                    return; // Invalid range
                }
                
                $this->filters[] = "$field BETWEEN ? AND ?";
                $this->params[] = trim($val1);
                $this->params[] = trim($val2);
                break;

            case 'LIKE':
                $this->filters[] = "$field LIKE ?";
                $this->params[] = "%$value%";
                break;
            
            case 'IN':
                 // Not implemented for this MVP simplified version
                 break;

            case '=':
            case '>':
            case '<':
            case '>=':
            case '<=':
            case '<>':
                $this->filters[] = "$field $operator ?";
                $this->params[] = $value;
                break;
        }
    }

    /**
     * Builds the WHERE clause.
     *
     * @return string The WHERE clause (starting with " WHERE " or " AND " if part of larger context).
     */
    public function buildWhereClause($existingWhere = false) {
        if (empty($this->filters)) {
            return '';
        }
        
        $prefix = $existingWhere ? ' AND ' : ' WHERE ';
        return $prefix . implode(' AND ', $this->filters);
    }

    /**
     * Returns the parameters array for PDO execution.
     *
     * @return array
     */
    public function getParameters() {
        return $this->params;
    }
}
