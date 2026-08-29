<?php
/**
 * Global responsive helpers.
 * This file only controls shared behavior.
 * Page containers and grids remain controlled by each page.
 */
?>
<style>
html, body {
    max-width: 100%;
    overflow-x: hidden;
}

img, canvas {
    max-width: 100%;
    height: auto;
}

table {
    max-width: 100%;
}

@media (max-width: 768px) {
    .modal-box,
    .modal-content,
    .jashs-confirm-dialog {
        max-width: calc(100vw - 32px) !important;
        max-height: calc(100vh - 32px) !important;
        overflow-y: auto !important;
    }

    .table-responsive,
    .table-wrapper {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    input, select, textarea, button {
        max-width: 100%;
    }
}
</style>
