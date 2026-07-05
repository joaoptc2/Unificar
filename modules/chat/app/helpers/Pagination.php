<?php
class Pagination
{
    public int $total;
    public int $currentPage;
    public int $perPage;
    public int $totalPages;
    public int $offset;

    public function __construct(int $total, int $currentPage = 1, int $perPage = 20)
    {
        $this->total       = $total;
        $this->perPage     = $perPage;
        $this->totalPages  = max(1, (int) ceil($total / $perPage));
        $this->currentPage = max(1, min($currentPage, $this->totalPages));
        $this->offset      = ($this->currentPage - 1) * $perPage;
    }

    public function render(string $extraParams = ''): string
    {
        if ($this->totalPages <= 1) return '';

        $html = '<nav><ul class="pagination pagination-sm justify-content-center mb-0">';

        if ($this->currentPage > 1) {
            $html .= '<li class="page-item"><a class="page-link" href="?m=chat&p=' . ($this->currentPage - 1) . $extraParams . '">&laquo;</a></li>';
        }

        $start = max(1, $this->currentPage - 2);
        $end   = min($this->totalPages, $this->currentPage + 2);

        for ($i = $start; $i <= $end; $i++) {
            $active = $i === $this->currentPage ? ' active' : '';
            $html .= '<li class="page-item' . $active . '"><a class="page-link" href="?m=chat&p=' . $i . $extraParams . '">' . $i . '</a></li>';
        }

        if ($this->currentPage < $this->totalPages) {
            $html .= '<li class="page-item"><a class="page-link" href="?m=chat&p=' . ($this->currentPage + 1) . $extraParams . '">&raquo;</a></li>';
        }

        $html .= '</ul></nav>';
        return $html;
    }
}
