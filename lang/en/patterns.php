<?php
/**
 * Padrões para frases com um pedaço escrito pelas pessoas (um título, um nome novo, uma lista)
 * que por isso não cabem no dicionário de frases fixas. Ficam todos na chave '~':
 * [expressão regular, tradução com $1, $2…]. Só se experimentam quando o dicionário falha.
 */
return [
    '~' => [
        ["/^Remover a notícia '(.*)'\\?$/u", "Remove the news item '$1'?"],
        ["/^Remover a fotografia '(.*)'\\?$/u", "Remove the photo '$1'?"],
        ["/^Remover a fotografia '(.*)$/u", "Remove the photo '$1"],
        ["/^Remover o comunicado '(.*)'\\?$/u", "Remove the announcement '$1'?"],
        ["/^Remover o movimento '(.*)'\\?$/u", "Remove the entry '$1'?"],
        ["/^Remover o treino '(.*)' e as presenças associadas\\?$/u", "Remove the training '$1' and its attendance records?"],
        ["/^Remover a tática '(.*)'\\?$/u", "Remove the play '$1'?"],
        ["/^Cancelar a compra (\\S+)\\?$/u", 'Cancel the purchase $1?'],
        ["/^Apagar a conta (\\S+)\\? Esta pessoa deixa de poder entrar\\.$/u", 'Delete the account $1? This person will no longer be able to sign in.'],
        ['/^Repor palavra-passe de (\S+)$/u', 'Reset password of $1'],
        ['/^Ampliar: (.*)$/u', 'Enlarge: $1'],
        ['/^Aprovar (.*)\? É criado o clube e a conta de administrador (\S+)\.$/u', 'Approve $1? The club and the administrator account $2 will be created.'],
        ['/^(.*) foi aprovado\. (.*) já pode entrar com o utilizador (\S+)\.$/u', '$1 was approved. $2 can now sign in with the username $3.'],
        ['/^Pedido de (.*) recusado\. Não foi criado nenhum clube nem conta\.$/u', 'Request from $1 rejected. No club or account was created.'],
        ['/^Pedido de (.*) recusado\. A conta continua como adepto\.$/u', 'Request from $1 rejected. The account stays as a fan.'],
        ['/^(.*) foi aceite e já está no plantel com o número (\d+)\.$/u', '$1 was accepted and is now in the squad with number $2.'],
        ['/^(.*) passa a treinar: (.*)\.$/u', '$1 now coaches: $2.'],
        ['/^Ainda há jogadores no escalão (.*)\. Mude-os de escalão antes de o retirar\.$/u', 'There are still players in the age group $1. Move them to another age group before removing it.'],
        ['/^Não é treinador dos (.*) de (.*)\. Os seus escalões: (.*)\.$/u', 'You are not a coach of the $1 of $2. Your age groups: $3.'],
        ['/^Cópia guardada no servidor: (.*)$/u', 'Backup saved on the server: $1'],
        ['/^Ligação para (.*) \((.*)\) escolher palavra-passe nova$/u', 'Link for $1 ($2) to choose a new password'],
        ['/^Nova notícia: (.*)$/u', 'New news item: $1'],
        ['/^Novo jogo marcado: vs (.*)$/u', 'New game scheduled: vs $1'],
        ['/^Vitória (\d+)–(\d+) contra (.*)$/u', 'Win $1–$2 against $3'],
        ['/^Derrota (\d+)–(\d+) contra (.*)$/u', 'Loss $1–$2 against $3'],
        ['/^Pedido aceite: já é jogador de (.*)$/u', 'Request accepted: you are now a player of $1'],
        ['/^Olá, (.*)$/u', 'Hello, $1'],
        ['/^Treina: (.*)$/u', 'Coaches: $1'],
        ['/^Conta (\S+) — (.*)\.$/u', 'Account $1 — $2.'],
    ],
];
