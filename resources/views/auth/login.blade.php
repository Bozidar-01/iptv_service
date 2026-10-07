<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Secure Entry - IPTV Control</title>
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}">
    <style>
        body {
            background-color: #050507;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            overflow: hidden;
            padding: 20px;
        }
        
        .login-gateway {
            width: 100%;
            max-width: 520px;
            padding: 80px;
            background: rgba(17, 17, 20, 0.6);
            backdrop-filter: blur(40px) saturate(180%);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 40px;
            box-shadow: 0 40px 100px -20px rgba(0, 0, 0, 0.8);
            text-align: center;
            position: relative;
            z-index: 10;
        }
        
        .glow-sphere {
            position: absolute;
            width: 600px;
            height: 600px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(34, 211, 238, 0.1) 0%, transparent 70%);
            filter: blur(80px);
            z-index: -1;
        }

        .sphere-1 { top: -200px; left: -200px; }
        .sphere-2 { bottom: -300px; right: -200px; background: radial-gradient(circle, rgba(129, 102, 248, 0.08) 0%, transparent 70%); }
        
        .login-identity {
            font-size: 2.5rem;
            font-weight: 900;
            background: linear-gradient(135deg, var(--accent), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 8px;
            letter-spacing: -0.06em;
        }
        
        .security-label {
            font-size: 0.7rem;
            font-weight: 800;
            color: var(--text-dim);
            text-transform: uppercase;
            letter-spacing: 0.5em;
            margin-bottom: 60px;
        }

        .auth-error {
            background: rgba(248, 113, 113, 0.1);
            color: var(--danger);
            padding: 16px;
            border-radius: var(--radius-md);
            font-size: 0.8rem;
            font-weight: 700;
            margin-bottom: 40px;
            border: 1px solid rgba(248, 113, 113, 0.2);
        }
    </style>
</head>
<body>
    <div class="glow-sphere sphere-1"></div>
    <div class="glow-sphere sphere-2"></div>

    <div class="login-gateway fade-in">
        <h1 class="login-identity">CONTROL</h1>
        <p class="security-label">Infrastructure Gateway</p>

        @if($errors->any())
            <div class="auth-error">
                <svg style="width: 18px; height: 18px; margin-bottom: 4px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                <div style="margin-top: 4px;">{{ $errors->first() }}</div>
            </div>
        @endif

        <form method="POST" action="{{ route('login') }}" style="display: flex; flex-direction: column; gap: 32px;">
            @csrf
            <div style="text-align: left;">
                <label class="input-label">Administrator ID</label>
                <input type="text" name="username" required autofocus class="input-field" placeholder="Username" style="height: 64px; font-size: 1.1rem; border-radius: 20px;">
            </div>

            <div style="text-align: left;">
                <label class="input-label">Clearance Key</label>
                <input type="password" name="password" required class="input-field" placeholder="Password" style="height: 64px; font-size: 1.1rem; border-radius: 20px;">
            </div>

            <button type="submit" class="action-btn btn-electric shadow-hard" style="height: 64px; font-size: 1.1rem; border-radius: 22px; margin-top: 20px; text-transform: uppercase; letter-spacing: 0.2em;">
                Initalize Session
            </button>
        </form>

        <p style="margin-top: 60px; font-size: 0.65rem; font-weight: 800; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.15em;">
            &copy; {{ date('Y') }} Protected Signal Network
        </p>
    </div>
</body>
</html>
