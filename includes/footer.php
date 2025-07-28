<?php
/**
 * Global Footer Template
 */
?>
        </main> <!-- Close main-content from header -->
        
        <!-- Main Footer -->
        <footer class="main-footer">
            <div class="container">
                <div class="footer-grid">
                    <!-- Quick Links -->
                    <div class="footer-section">
                        <h3 class="footer-title">Quick Links</h3>
                        <ul class="footer-links">
                            <li><a href="<?php echo SITE_URL; ?>/about">About Us</a></li>
                            <li><a href="<?php echo SITE_URL; ?>/properties">Browse Properties</a></li>
                            <li><a href="<?php echo SITE_URL; ?>/faq">FAQ</a></li>
                            <li><a href="<?php echo SITE_URL; ?>/contact">Contact Us</a></li>
                        </ul>
                    </div>
                    
                    <!-- For Students -->
                    <div class="footer-section">
                        <h3 class="footer-title">For Students</h3>
                        <ul class="footer-links">
                            <li><a href="<?php echo SITE_URL; ?>/how-it-works">How It Works</a></li>
                            <li><a href="<?php echo SITE_URL; ?>/student-guide">Student Guide</a></li>
                            <li><a href="<?php echo SITE_URL; ?>/safety-tips">Safety Tips</a></li>
                        </ul>
                    </div>
                    
                    <!-- For Owners -->
                    <div class="footer-section">
                        <h3 class="footer-title">For Property Owners</h3>
                        <ul class="footer-links">
                            <li><a href="<?php echo SITE_URL; ?>/list-your-property">List Your Property</a></li>
                            <li><a href="<?php echo SITE_URL; ?>/pricing">Pricing</a></li>
                            <li><a href="<?php echo SITE_URL; ?>/owner-resources">Resources</a></li>
                        </ul>
                    </div>
                    
                    <!-- Contact Info -->
                    <div class="footer-section">
                        <h3 class="footer-title">Contact Us</h3>
                        <address>
                            <p><i class="fas fa-map-marker-alt"></i> University District, Campus City</p>
                            <p><i class="fas fa-phone"></i> +233 24 123 4567</p>
                            <p><i class="fas fa-envelope"></i> info@unihomes.com</p>
                        </address>
                        
                        <!-- Social Media -->
                        <div class="social-links">
                            <a href="#" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
                            <a href="#" aria-label="Twitter"><i class="fab fa-twitter"></i></a>
                            <a href="#" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
                            <a href="#" aria-label="LinkedIn"><i class="fab fa-linkedin-in"></i></a>
                        </div>
                    </div>
                </div>
                
                <!-- Copyright -->
                <div class="footer-bottom">
                    <p>&copy; <?php echo date('Y'); ?> UniHomes Accommodation System. All rights reserved.</p>
                    <div class="legal-links">
                        <a href="<?php echo SITE_URL; ?>/privacy">Privacy Policy</a>
                        <a href="<?php echo SITE_URL; ?>/terms">Terms of Service</a>
                        <a href="<?php echo SITE_URL; ?>/sitemap">Sitemap</a>
                    </div>
                </div>
            </div>
        </footer>
        
        <!-- Back to Top Button -->
        <button class="back-to-top" aria-label="Back to top">
            <i class="fas fa-arrow-up"></i>
        </button>
        
        <!-- JavaScript -->
        <script src="<?php echo SITE_URL; ?>/assets/js/main.js"></script>
        <?php if (isset($jsFile)): ?>
        <script src="<?php echo SITE_URL; ?>/assets/js/<?php echo $jsFile; ?>.js"></script>
        <?php endif; ?>
        
        <!-- Inline JavaScript for page-specific functionality -->
        <script>
            // Initialize any global JavaScript here
            document.addEventListener('DOMContentLoaded', function() {
                // Back to top button
                const backToTop = document.querySelector('.back-to-top');
                if (backToTop) {
                    window.addEventListener('scroll', function() {
                        if (window.pageYOffset > 300) {
                            backToTop.style.display = 'block';
                        } else {
                            backToTop.style.display = 'none';
                        }
                    });
                    
                    backToTop.addEventListener('click', function(e) {
                        e.preventDefault();
                        window.scrollTo({top: 0, behavior: 'smooth'});
                    });
                }
                
                // Mobile menu toggle
                const mobileToggle = document.querySelector('.mobile-menu-toggle');
                if (mobileToggle) {
                    mobileToggle.addEventListener('click', function() {
                        document.querySelector('.main-nav').classList.toggle('active');
                    });
                }
            });
        </script>
        
        <?php echo $additionalScripts ?? ''; ?>
    </body>
</html>