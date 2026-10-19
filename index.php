<?php include 'config.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/png" href="logos/logo.png">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Zabbix Reports Login</title>

<style>
*{
    margin:0;
    padding:0;
    box-sizing:border-box;
}

body{
    font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;
    min-height:100vh;
    display:flex;
    justify-content:center;
    align-items:center;
    padding:20px;
    background:linear-gradient(135deg,#ffffff 0%, #fff7f2 45%, #ffe4ec 100%);
}

.login-container{
    width:100%;
    max-width:430px;
}

.login-card{
    background:#ffffff;
    border-radius:22px;
    padding:38px;
    border:2px solid #ffb48a;   /* Added visible border */
    box-shadow:0 20px 45px rgba(255,140,0,0.12);
}

.logo{
    text-align:center;
    margin-bottom:18px;
}

.logo img{
    max-width:90px;
    height:auto;
}

h2{
    text-align:center;
    color:#222;
    font-size:30px;
    margin-bottom:8px;
    font-weight:700;
}

.subtitle{
    text-align:center;
    color:#666;
    font-size:14px;
    margin-bottom:28px;
}

.form-group{
    margin-bottom:18px;
}

label{
    display:block;
    font-size:14px;
    color:#444;
    margin-bottom:8px;
    font-weight:600;
}

input{
    width:100%;
    padding:14px 16px;
    border:1px solid #ffb48a;   /* Input border added */
    border-radius:12px;
    outline:none;
    background:#fffaf8;
    font-size:15px;
    transition:0.3s;
}

input:focus{
    border-color:#ff8c00;
    box-shadow:0 0 0 4px rgba(255,140,0,0.10);
    background:#fff;
}

input::placeholder{
    color:#aaa;
}

button{
    width:100%;
    padding:15px;
    border:none;
    border-radius:12px;
    background:linear-gradient(135deg,#ff8c00,#ff6aa2);
    color:#fff;
    font-size:16px;
    font-weight:700;
    cursor:pointer;
    transition:0.3s;
    margin-top:8px;
}

button:hover{
    transform:translateY(-2px);
    box-shadow:0 14px 24px rgba(255,105,140,0.18);
}

.footer{
    text-align:center;
    margin-top:20px;
    font-size:13px;
    color:#888;
}

@media(max-width:480px){
    .login-card{
        padding:28px;
    }

    h2{
        font-size:25px;
    }
}
</style>
</head>

<body>

<div class="login-container">
    <div class="login-card">

        <div class="logo">
            <img src="logos/logo.png" alt="Logo">
        </div>

        <h2>Zabbix Reports</h2>
        <p class="subtitle">Login to access PDF Reports Dashboard</p>

        <form method="post" action="login.php">

            <div class="form-group">
                <label>Zabbix URL</label>
                <input type="text" name="url" placeholder="https://your-zabbix-url/zabbix" required>
            </div>

            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" placeholder="Enter username" required>
            </div>

            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" placeholder="Enter password" required>
            </div>

            <button type="submit">Login</button>

        </form>

        <div class="footer">
            Secure Reporting Portal
        </div>

    </div>
</div>

</body>
</html>