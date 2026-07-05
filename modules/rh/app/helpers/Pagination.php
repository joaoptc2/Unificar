<?php
/**
 * Helper de paginação
 */
class Pagination
{
    public int $currentPage;
    public int $totalItems;
    public int $perPage;
    public int $totalPages;
    public int $offset;

    public function __construct(int $totalItems, int $currentPage = 1, int $perPage = 20)
    {
        $this->totalItems  = max(0, $totalItems);
        $this->perPage     = max(1, $perPage);
        $this->totalPages  = max(1, (int) ceil($this->totalItems / $this->perPage));
        $this->currentPage = max(1, min($currentPage, $this->totalPages));
        $this->offset      = ($this->currentPage - 1) * $this->perPage;
    }

    /**
     * Gera HTML da paginação Bootstrap
     */
    public function render(string $baseUrl = ''): string
    {
        if ($this->totalPages <= 1) return '';

        // Preservar parâmetros GET existentes
        $params = $_GET;
        unset($params['p']);

        $queryString = http_build_query($params);
        $separator = $queryString ? '&' : '';

        $html = '<nav aria-label="Paginação"><ul class="pagination pagination-sm justify-content-center mb-0">';

        // Anterior
        if ($this->currentPage > 1) {
            $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . '?' . $queryString . $separator . 'p=' . ($this->currentPage - 1) . '">&laquo;</a></li>';
        } else {
            $html .= '<li class="page-item disabled"><span class="page-link">&laquo;</span></li>';
        }

        // Páginas
        $start = max(1, $this->currentPage - 2);
        $end = min($this->totalPages, $this->currentPage + 2);

        if ($start > 1) {
            $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . '?' . $queryString . $separator . 'p=1">1</a></li>';
            if ($start > 2) $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }

        for ($i = $start; $i <= $end; $i++) {
            if ($i == $this->currentPage) {
                $html .= '<li class="page-item active"><span class="page-link">' . $i . '</span></li>';
            } else {
                $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . '?' . $queryString . $separator . 'p=' . $i . '">' . $i . '</a></li>';
            }
        }

        if ($end < $this->totalPages) {
            if ($end < $this->totalPages - 1) $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
            $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . '?' . $queryString . $separator . 'p=' . $this->totalPages . '">' . $this->totalPages . '</a></li>';
        }

        // Próximo
        if ($this->currentPage < $this->totalPages) {
            $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . '?' . $queryString . $separator . 'p=' . ($this->currentPage + 1) . '">&raquo;</a></li>';
        } else {
            $html .= '<li class="page-item disabled"><span class="page-link">&raquo;</span></li>';
        }

        $html .= '</ul></nav>';
        return $html;
    }
}
