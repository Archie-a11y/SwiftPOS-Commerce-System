<?php
if (!function_exists('render_shared_alerts')) {
    function render_shared_alerts($l = [], $successMessage = null, $errorMessage = null, array $options = []): void
    {
        if (empty($l) && isset($GLOBALS['l'])) {
            $l = $GLOBALS['l'];
        }

        $printHidden = (bool)($options['print_hidden'] ?? false);
        $extraClass = $options['extra_class'] ?? '';
        $alertClass = trim('shadow-sm border-0 rounded-4 mb-4 ' . $extraClass);

        $resolvedSuccess = $successMessage;
        if ($resolvedSuccess === null || $resolvedSuccess === '') {
            if (!empty($_SESSION['success_msg_key'])) {
                $key = $_SESSION['success_msg_key'];
                $arg = $_SESSION['success_msg_arg'] ?? null;
                $translated = $l[$key] ?? $key;
                $resolvedSuccess = $arg !== null ? sprintf($translated, $arg) : $translated;
                unset($_SESSION['success_msg_key'], $_SESSION['success_msg_arg']);
            } elseif (!empty($_SESSION['success_msg'])) {
                $resolvedSuccess = $_SESSION['success_msg'];
                unset($_SESSION['success_msg']);
            }
        }

        $resolvedError = $errorMessage;
        if ($resolvedError === null || $resolvedError === '') {
            if (!empty($_SESSION['error_msg_key'])) {
                $key = $_SESSION['error_msg_key'];
                $arg = $_SESSION['error_msg_arg'] ?? null;
                $translated = $l[$key] ?? $key;
                $resolvedError = $arg !== null ? sprintf($translated, $arg) : $translated;
                unset($_SESSION['error_msg_key'], $_SESSION['error_msg_arg']);
            } elseif (!empty($_SESSION['error_msg'])) {
                $resolvedError = $_SESSION['error_msg'];
                unset($_SESSION['error_msg']);
            }
        }

        if (!empty($resolvedSuccess)):
            ?>
            <div class="alert alert-success alert-dismissible fade show <?php echo $printHidden ? 'd-print-none' : ''; ?> <?php echo htmlspecialchars($alertClass); ?>" role="alert">
                <div class="d-flex align-items-center">
                    <i class="fas fa-check-circle fs-5 me-3"></i>
                    <div><?php echo htmlspecialchars($resolvedSuccess); ?></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php
        endif;

        if (!empty($resolvedError)):
            ?>
            <div class="alert alert-danger alert-dismissible fade show <?php echo $printHidden ? 'd-print-none' : ''; ?> <?php echo htmlspecialchars($alertClass); ?>" role="alert">
                <div class="d-flex align-items-center">
                    <i class="fas fa-circle-exclamation fs-5 me-3"></i>
                    <div><?php echo htmlspecialchars($resolvedError); ?></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php
        endif;
    }
}
?>
