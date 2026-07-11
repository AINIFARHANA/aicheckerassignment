<footer>
        <div class="container">
            <div class="row">
                <div class="col-lg-4">
                    <h5>AI Assignment Checker</h5>
                    <p class="small">Empowering academic integrity with cutting-edge AI technology. Fast, accurate, and secure.</p>
                </div>
                <div class="col-lg-2 col-md-4">
                    <h6>Quick Links</h6>
                    <ul class="list-unstyled">
                        <li><a href="index.php">Home</a></li>
                        <li><a href="services.php">Services</a></li>
                        <li><a href="plan.php">Plans</a></li>
                        <li><a href="contacts.php">Contact</a></li>
                    </ul>
                </div>
                <div class="col-lg-2 col-md-4">
                    <h6>Resources</h6>
                    <ul class="list-unstyled">
                        <li><a href="contacts.php">FAQ</a></li>
                        <li><a href="privacypolicy.php">Privacy Policy</a></li>
                        <li><a href="terms.php">Terms of Service</a></li>
                    </ul>
                </div>
                <div class="col-lg-4 col-md-4">
                    <h6>Stay Updated</h6>
                    <p class="small">Get the latest updates on our AI tools and features.</p>
                </div>
            </div>
            <div class="border-top text-center">
                <p class="mb-0">&copy; 2025 AI Assignment Checker. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <style>
        footer {
            background: var(--bg-dark);
            border-top: 1px solid var(--border-subtle);
            color: var(--text-muted);
            padding: 28px 0;
            position: relative; z-index: 2;
        }
        footer::before {
            content: ''; position: absolute;
            top: 0; left: 0; right: 0; height: 1px;
            background: linear-gradient(90deg, transparent, rgba(244,209,255,0.15), transparent);
        }
        footer a {
            color: var(--text-muted);
            text-decoration: none; transition: all 0.3s ease; font-size: 0.85rem;
        }
        footer a:hover { color: var(--primary-light); }
        footer h5 {
            font-weight: 700; color: var(--text-bright); font-size: 1rem; margin-bottom: 8px;
        }
        footer h6 {
            font-weight: 600; color: rgba(244, 209, 255, 0.7);
            font-size: 0.85rem; margin-bottom: 12px;
        }
        footer p.small { font-size: 0.82rem; line-height: 1.7; margin-bottom: 0; }
        footer ul li { margin-bottom: 6px; }
        footer .border-top {
            border-color: var(--border-subtle) !important;
            margin-top: 20px; padding-top: 18px; font-size: 0.78rem;
        }
    </style>

    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>

    <script>
        // AOS init
        AOS.init({ duration: 800, once: true, offset: 80 });

        // Navbar scroll
        window.addEventListener('scroll', function() {
            const nb = document.querySelector('.navbar');
            if (nb) {
                if (window.scrollY > 50) nb.classList.add('scrolled');
                else nb.classList.remove('scrolled');
            }
        });

        // Smooth scroll
        document.querySelectorAll('a[href^="#"]').forEach(function(a) {
            a.addEventListener('click', function(e) {
                var t = document.querySelector(this.getAttribute('href'));
                if (t) { e.preventDefault(); t.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
            });
        });

        // Cursor glow
        var cg = document.getElementById('cursorGlow');
        if (cg) {
            document.addEventListener('mousemove', function(e) {
                cg.style.left = e.clientX + 'px';
                cg.style.top = e.clientY + 'px';
            });
        }

        // Starfield
        (function() {
            var c = document.getElementById('starfield');
            if (!c) return;
            var ctx = c.getContext('2d');
            var stars = [], N = 120;
            function resize() { c.width = window.innerWidth; c.height = window.innerHeight; }
            function create() {
                stars = [];
                for (var i = 0; i < N; i++) {
                    stars.push({
                        x: Math.random() * c.width, y: Math.random() * c.height,
                        s: Math.random() * 1.5 + 0.3, sp: Math.random() * 0.3 + 0.05,
                        o: Math.random() * 0.6 + 0.1, ts: Math.random() * 0.02 + 0.005,
                        to: Math.random() * Math.PI * 2
                    });
                }
            }
            function draw() {
                ctx.clearRect(0, 0, c.width, c.height);
                var t = Date.now() * 0.001;
                for (var i = 0; i < stars.length; i++) {
                    var st = stars[i];
                    st.y -= st.sp;
                    if (st.y < -5) { st.y = c.height + 5; st.x = Math.random() * c.width; }
                    var tw = Math.sin(t * st.ts * 10 + st.to) * 0.3 + 0.7;
                    var a = st.o * tw;
                    ctx.beginPath(); ctx.arc(st.x, st.y, st.s, 0, Math.PI * 2);
                    ctx.fillStyle = 'rgba(244,209,255,' + a + ')'; ctx.fill();
                    if (st.s > 1) {
                        ctx.beginPath(); ctx.arc(st.x, st.y, st.s * 3, 0, Math.PI * 2);
                        ctx.fillStyle = 'rgba(244,209,255,' + (a * 0.1) + ')'; ctx.fill();
                    }
                }
                requestAnimationFrame(draw);
            }
            resize(); create(); draw();
            window.addEventListener('resize', function() { resize(); create(); });
        })();
    </script>
</body>
</html>