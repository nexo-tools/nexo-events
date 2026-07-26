<?php

// Páginas legais (privacidade + termos). Fonte: lang/es/legal.php.
// NÃO revisado por advogado: escrito para descrever com precisão o que este
// código realmente faz.
return [
    'updated' => 'Última atualização: 26 de julho de 2026',

    'privacy' => [
        'title' => 'Privacidade',
        'intro' => 'Esta instância do Nexo Events é open source e self-hosted. Coletamos o mínimo necessário para um evento funcionar, e nada além disso. Sem cookies de rastreamento, sem análise de terceiros e sem envio de dados a redes de publicidade.',
        'sections' => [
            [
                'h' => 'O que guardamos sobre os organizadores',
                'p' => 'Nome, e-mail e uma versão cifrada (hash) da senha. O e-mail é usado para verificar a conta, recuperar o acesso e avisar sobre os seus próprios eventos. Se você entrar com o Nexo ID, guardamos também o identificador que esse serviço nos fornece para reconhecê-lo.',
            ],
            [
                'h' => 'O que guardamos sobre os participantes',
                'p' => 'O nome e o e-mail informados ao se inscrever num evento, e se você entrou ou não. Não criamos conta nem pedimos senha. Esses dados ficam visíveis para o organizador do evento: é quem precisa saber quem vai.',
            ],
            [
                'h' => 'Os ingressos e o código QR',
                'p' => 'O código do seu ingresso é um valor aleatório sem nenhum dado seu dentro. No banco de dados guardamos apenas a sua impressão (hash), nunca o código em si: mesmo com acesso ao banco, ninguém conseguiria fabricar ingressos válidos. Se você pedir o reenvio, um novo código é gerado e o anterior deixa de funcionar.',
            ],
            [
                'h' => 'Métricas sem cookies',
                'p' => 'Contamos quantas pessoas distintas viram a página de um evento usando uma impressão calculada com a data do dia e depois descartada: não guardamos o seu IP nem o seu navegador, e a impressão de hoje não pode ser comparada com a de amanhã. Não sabemos quem você é nem podemos segui-lo entre sites.',
            ],
            [
                'h' => 'Cookies',
                'p' => 'Apenas os necessários para o site funcionar: o de sessão (para manter você identificado, se tiver conta) e os que lembram o idioma e o tema claro/escuro escolhidos. Nenhum serve para publicidade ou rastreamento.',
            ],
            [
                'h' => 'E-mails',
                'p' => 'Os ingressos e os e-mails da conta são enviados por um provedor externo, que necessariamente processa o endereço de destino e o conteúdo da mensagem para poder entregá-la.',
            ],
            [
                'h' => 'Por quanto tempo',
                'p' => 'Os dados de um evento são mantidos enquanto o organizador mantiver o evento e a sua conta. Ao excluir um evento, excluem-se os seus ingressos e registros associados.',
            ],
            [
                'h' => 'Seus direitos',
                'p' => 'Você pode solicitar acesso aos seus dados, correção ou exclusão escrevendo a quem opera esta instância (o contato está na página de ajuda). Se você se inscreveu num evento, o organizador também pode removê-lo da lista.',
            ],
            [
                'h' => 'Outras instâncias',
                'p' => 'O Nexo Events pode ser instalado em qualquer servidor. Cada instalação é independente e responsável pelos seus próprios dados: esta política trata apenas desta instância.',
            ],
        ],
    ],

    'terms' => [
        'title' => 'Termos de uso',
        'intro' => 'Ao usar esta instância do Nexo Events você aceita o que segue. É um serviço gratuito, oferecido como está.',
        'sections' => [
            [
                'h' => 'O que é o serviço',
                'p' => 'Uma ferramenta para publicar eventos gratuitos, receber inscrições por e-mail e validar ingressos com QR na porta. Não processamos pagamentos nem vendemos ingressos.',
            ],
            [
                'h' => 'Sua conta',
                'p' => 'Você precisa de uma conta para criar eventos e de um e-mail verificado para publicá-los. Você é responsável pelo que acontece com a sua conta e por manter a sua senha segura.',
            ],
            [
                'h' => 'Responsabilidade pelos seus eventos',
                'p' => 'O conteúdo de um evento, a sua veracidade, a sua realização e o tratamento dos dados de quem se inscreve são responsabilidade do organizador. Quem publica um evento atua como responsável por esses dados perante os participantes e deve cumprir as normas aplicáveis.',
            ],
            [
                'h' => 'Uso indevido',
                'p' => 'Não é permitido publicar eventos falsos, enganosos ou fraudulentos, se passar por terceiros, coletar dados para fins alheios ao evento, nem publicar conteúdo ilegal. Qualquer pessoa pode denunciar um evento, e quem opera esta instância pode retirá-lo do ar: a página deixa de estar disponível, as inscrições fecham e os ingressos emitidos deixam de validar na porta.',
            ],
            [
                'h' => 'Disponibilidade',
                'p' => 'O serviço é oferecido sem garantias de disponibilidade. Fazemos o razoável para mantê-lo no ar, sobretudo durante os eventos, mas pode haver interrupções. Um evento com conectividade duvidosa na porta deve sempre ter um plano alternativo.',
            ],
            [
                'h' => 'Limite de responsabilidade',
                'p' => 'Quem opera esta instância não se responsabiliza por danos decorrentes do uso do serviço, incluindo eventos que não aconteçam, ingressos que não possam ser validados ou perda de dados.',
            ],
            [
                'h' => 'Software livre',
                'p' => 'O Nexo Events é distribuído sob licença MIT: você pode ler o código, modificá-lo e hospedar a sua própria instância. O software é fornecido sem garantias, conforme essa licença.',
            ],
            [
                'h' => 'Alterações',
                'p' => 'Estes termos podem mudar. A data acima indica a última atualização.',
            ],
        ],
    ],
];
