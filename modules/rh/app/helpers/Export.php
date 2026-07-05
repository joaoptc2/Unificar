<?php
/**
 * Exportação de dados para CSV (compatível com Excel)
 */
class Export
{
    /**
     * Exporta dados para CSV com BOM UTF-8 (compatível com Excel)
     *
     * @param string $filename Nome do arquivo
     * @param array $headers Cabeçalhos das colunas
     * @param array $rows Dados (array de arrays)
     */
    public static function csv(string $filename, array $headers, array $rows): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');

        // BOM UTF-8 para Excel reconhecer acentos
        fwrite($output, "\xEF\xBB\xBF");

        // Cabeçalhos
        fputcsv($output, $headers, ';');

        // Dados
        foreach ($rows as $row) {
            fputcsv($output, $row, ';');
        }

        fclose($output);
        exit;
    }
}
