/**
 * lcni-dnse-login.js
 * Xử lý form đăng nhập bằng tài khoản DNSE trên trang login.
 */
(function () {
    'use strict';

    const CFG = window.lcniDnseLogin || {};

    document.addEventListener('DOMContentLoaded', function () {
        const toggle   = document.getElementById('lcni-dnse-login-toggle');
        const form     = document.getElementById('lcni-dnse-login-form');
        const userInput = document.getElementById('lcni-dnse-login-user');
        const passInput = document.getElementById('lcni-dnse-login-pass');
        const btn      = document.getElementById('lcni-dnse-login-btn');
        const errEl    = document.getElementById('lcni-dnse-login-error');

        if (!toggle || !form || !btn) return;

        // Toggle form hiện/ẩn
        toggle.addEventListener('click', function () {
            const isHidden = form.style.display === 'none';
            form.style.display = isHidden ? 'block' : 'none';
            toggle.style.borderColor = isHidden ? '#1d4ed8' : '#d1d5db';
            toggle.style.color       = isHidden ? '#1d4ed8' : '#374151';
            if (isHidden && userInput) userInput.focus();
        });

        // Enter key trong password field → submit
        if (passInput) {
            passInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') btn.click();
            });
        }

        btn.addEventListener('click', async function () {
            const username = (userInput?.value || '').trim();
            const password = passInput?.value || '';

            if (!username || !password) {
                showError('Vui lòng nhập tài khoản và mật khẩu DNSE.');
                return;
            }

            btn.disabled = true;
            btn.textContent = 'Đang xác thực...';
            hideError();

            try {
                const body = new URLSearchParams({
                    action:        CFG.action    || 'lcni_dnse_login',
                    nonce:         CFG.nonce     || '',
                    dnse_username: username,
                    dnse_password: password,
                    redirect_to:   CFG.redirectTo || '',
                });

                const res = await fetch(CFG.ajaxUrl || '/wp-admin/admin-ajax.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString(),
                });

                const data = await res.json();

                if (data.success && data.data?.redirect) {
                    btn.textContent = '✓ Đăng nhập thành công, đang chuyển hướng...';
                    window.location.href = data.data.redirect;
                } else {
                    const msg = data.data || 'Đăng nhập thất bại. Vui lòng thử lại.';
                    showError(typeof msg === 'string' ? msg : JSON.stringify(msg));
                    btn.disabled = false;
                    btn.textContent = 'Đăng nhập với DNSE';
                }
            } catch (e) {
                showError('Lỗi kết nối. Vui lòng thử lại.');
                btn.disabled = false;
                btn.textContent = 'Đăng nhập với DNSE';
            }
        });

        function showError(msg) {
            if (!errEl) return;
            errEl.textContent = msg;
            errEl.style.display = 'block';
        }

        function hideError() {
            if (!errEl) return;
            errEl.style.display = 'none';
        }
    });
})();
