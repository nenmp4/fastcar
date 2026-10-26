<?php require '/home/user/fastcar/includes/db.php'; echo getDB()->query("SELECT nome FROM clientes WHERE telefone='5511900001111'")->fetchColumn(),"\n";
