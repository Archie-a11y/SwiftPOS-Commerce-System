</div> <!-- 关闭内容容器 -->
        </main>
    </div> <!-- 关闭 row -->
</div> <!-- 关闭 container-fluid -->

<!-- 🛠 统一系统内置多语言用户指南模态框 -->
<div class="modal fade" id="guideModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow border-0">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">
                    <i class="fas fa-circle-question me-2 text-primary"></i><?php echo $l['guide_title']; ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-4">
                <p class="lh-lg mb-0 text-secondary">
                    <?php 
                        $page_guide_key = 'guide_' . ($current_page ?? 'default');
                        echo $l[$page_guide_key] ?? $l['guide_default']; 
                    ?>
                </p>
            </div>
            <div class="modal-footer border-top-0">
                <button type="button" class="btn btn-primary rounded-pill px-4" data-bs-dismiss="modal">
                    <?php echo $l['ok']; ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- 引入外部成熟的开源 JS 脚本 -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>
<script src="../assets/script.js"></script>
</body>
</html>