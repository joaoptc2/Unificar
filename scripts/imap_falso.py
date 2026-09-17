#!/usr/bin/env python3
"""
Servidor IMAP de mentira, para testar o cliente do portal sem depender de
um servidor de verdade.

Por que existe: imap.zoho.com não é alcançável do ambiente de teste, e
"funciona em teoria" não é teste. Aqui o cliente conversa com um servidor
que responde IMAP4rev1 de verdade — inclusive as partes que costumam
quebrar implementações escritas à mão:

  • literais ({123}\r\n seguido de bytes crus), que não podem ser lidos
    linha a linha;
  • resposta não solicitada no meio do diálogo (* 3 EXISTS);
  • cabeçalho RFC2047 (=?UTF-8?B?...?=) e corpo quoted-printable;
  • multipart/alternative, em que a parte de texto e a de HTML convivem;
  • uma mensagem com acento em ISO-8859-1, que é o caso que revela
    conversão de charset ausente.

Uso:  python3 scripts/imap_falso.py [porta]     (padrão 10143, sem TLS)
"""

import base64
import socket
import socketserver
import sys
import threading

USUARIO = "joao@hospital.br"
SENHA = "senha-de-aplicativo"

# ── As mensagens de teste ───────────────────────────────────────────────────
# Cada uma existe para exercitar um problema específico do cliente.

MSG1 = (
    "From: Maria Souza <maria@hospital.br>\r\n"
    "To: joao@hospital.br\r\n"
    # RFC2047 base64: o cliente tem de decodificar, senão o assunto aparece
    # como "=?UTF-8?B?..." na tela.
    "Subject: =?UTF-8?B?UmV1bmnDo28gZGEgcXVhbGlkYWRlIC0gdGVyw6dh?=\r\n"
    "Date: Mon, 15 Sep 2026 09:12:00 -0300\r\n"
    "Message-ID: <a1@hospital.br>\r\n"
    "MIME-Version: 1.0\r\n"
    "Content-Type: text/plain; charset=UTF-8\r\n"
    "Content-Transfer-Encoding: quoted-printable\r\n"
    "\r\n"
    # quoted-printable: "confirmação" e "próxima" quebram sem decodificação
    "Bom dia, Jo=C3=A3o.\r\n"
    "Segue a confirma=C3=A7=C3=A3o da reuni=C3=A3o da pr=C3=B3xima ter=C3=A7a=\r\n"
    "-feira, =C3=A0s 14h, na sala 3.\r\n"
    "Abra=C3=A7os,\r\nMaria\r\n"
)

MSG2 = (
    "From: =?ISO-8859-1?Q?Jos=E9_Almeida?= <jose@hospital.br>\r\n"
    "To: joao@hospital.br\r\n"
    "Subject: Escala de plantao\r\n"
    "Date: Tue, 16 Sep 2026 07:30:00 -0300\r\n"
    "Message-ID: <b2@hospital.br>\r\n"
    "MIME-Version: 1.0\r\n"
    # ISO-8859-1 sem codificação de transporte: o acento vem como byte 0xE9.
    "Content-Type: text/plain; charset=ISO-8859-1\r\n"
    "\r\n"
    "Jo\xe3o, a escala de plant\xe3o de outubro j\xe1 est\xe1 no sistema.\r\n"
)

MSG3 = (
    "From: RH <rh@hospital.br>\r\n"
    "To: joao@hospital.br\r\n"
    "Subject: Comunicado: campanha de vacinacao\r\n"
    "Date: Wed, 16 Sep 2026 11:00:00 -0300\r\n"
    "Message-ID: <c3@hospital.br>\r\n"
    "MIME-Version: 1.0\r\n"
    'Content-Type: multipart/alternative; boundary="LIMITE123"\r\n'
    "\r\n"
    "Esta parte antes do primeiro limite deve ser ignorada.\r\n"
    "--LIMITE123\r\n"
    "Content-Type: text/plain; charset=UTF-8\r\n"
    "Content-Transfer-Encoding: base64\r\n"
    "\r\n"
    + base64.b64encode(
        "A campanha de vacinação vai até sexta, no ambulatório.".encode("utf-8")
    ).decode()
    + "\r\n"
    "--LIMITE123\r\n"
    "Content-Type: text/html; charset=UTF-8\r\n"
    "Content-Transfer-Encoding: 7bit\r\n"
    "\r\n"
    "<p>A campanha de <b>vacinação</b> vai até sexta.</p>\r\n"
    "--LIMITE123--\r\n"
)



# As mensagens são BYTES, cada uma na codificação que o próprio cabeçalho
# declara. Guardá-las como texto e converter tudo com um encoding só produzia
# uma massa de teste MENTIROSA: a mensagem 3 dizia UTF-8 e ia para o fio em
# latin-1, e o cliente era acusado de um erro que estava no teste.
MENSAGENS = [
    MSG1.encode("utf-8"),                      # declara UTF-8, quoted-printable
    MSG2.encode("latin-1"),                    # declara ISO-8859-1, bytes altos crus
    MSG3.encode("utf-8"),                      # declara UTF-8, multipart
]


def _b(s):
    """Já são bytes; aceita texto por conveniência."""
    return s if isinstance(s, bytes) else s.encode("utf-8")


class Sessao(socketserver.BaseRequestHandler):
    def _envia(self, linha):
        self.request.sendall(_b(linha) if isinstance(linha, str) else linha)

    def _literal(self, tag_parte, corpo_bytes):
        """Responde com um literal {n}\r\n<bytes> — o caso que quebra
        clientes que leem sempre linha a linha."""
        self._envia(f"{tag_parte} {{{len(corpo_bytes)}}}\r\n".encode())
        self._envia(corpo_bytes)
        self._envia(b")\r\n")

    def handle(self):
        self.autenticado = False
        self.selecionada = False
        self._envia("* OK [CAPABILITY IMAP4rev1] Servidor de teste do portal\r\n")

        arquivo = self.request.makefile("rb")
        while True:
            linha = arquivo.readline()
            if not linha:
                return
            try:
                texto = linha.decode("utf-8", "replace").strip()
            except Exception:
                return
            if not texto:
                continue

            partes = texto.split(" ", 2)
            tag = partes[0]
            cmd = partes[1].upper() if len(partes) > 1 else ""
            resto = partes[2] if len(partes) > 2 else ""

            if cmd == "CAPABILITY":
                self._envia("* CAPABILITY IMAP4rev1\r\n")
                self._envia(f"{tag} OK CAPABILITY concluído\r\n")

            elif cmd == "LOGIN":
                # Aceita com e sem aspas, como um servidor real.
                args = resto.replace('"', "").split(" ", 1)
                if len(args) == 2 and args[0] == USUARIO and args[1] == SENHA:
                    self.autenticado = True
                    self._envia(f"{tag} OK LOGIN concluído\r\n")
                else:
                    self._envia(f"{tag} NO [AUTHENTICATIONFAILED] Credenciais recusadas\r\n")

            elif cmd == "SELECT" or cmd == "EXAMINE":
                if not self.autenticado:
                    self._envia(f"{tag} NO Faça LOGIN primeiro\r\n")
                    continue
                self.selecionada = True
                n = len(MENSAGENS)
                # Respostas NÃO SOLICITADAS antes do OK final: um cliente que
                # espera só a linha com a tag trava aqui.
                self._envia(f"* {n} EXISTS\r\n")
                self._envia("* 0 RECENT\r\n")
                self._envia("* OK [UIDVALIDITY 1758000000] UIDs válidos\r\n")
                self._envia(f"* OK [UIDNEXT {n + 1}] Próximo UID\r\n")
                self._envia("* FLAGS (\\Seen \\Answered \\Flagged \\Deleted)\r\n")
                self._envia(f"{tag} OK [READ-WRITE] SELECT concluído\r\n")

            elif cmd == "SEARCH" or (cmd == "UID" and resto.upper().startswith("SEARCH")):
                if not self.selecionada:
                    self._envia(f"{tag} NO Selecione uma caixa primeiro\r\n")
                    continue
                uids = " ".join(str(i + 1) for i in range(len(MENSAGENS)))
                self._envia(f"* SEARCH {uids}\r\n")
                self._envia(f"{tag} OK SEARCH concluído\r\n")

            elif cmd == "FETCH" or (cmd == "UID" and resto.upper().startswith("FETCH")):
                if not self.selecionada:
                    self._envia(f"{tag} NO Selecione uma caixa primeiro\r\n")
                    continue
                arg = resto[len("FETCH"):].strip() if cmd == "UID" else resto
                self._fetch(tag, arg)

            elif cmd == "LOGOUT":
                self._envia("* BYE Até logo\r\n")
                self._envia(f"{tag} OK LOGOUT concluído\r\n")
                return

            elif cmd == "NOOP":
                self._envia(f"{tag} OK NOOP concluído\r\n")

            else:
                self._envia(f"{tag} BAD Comando desconhecido: {cmd}\r\n")

    def _fetch(self, tag, arg):
        """FETCH <conjunto> (<itens>) — aceita 1:*, 1,2,3 e n."""
        partes = arg.split(" ", 1)
        conjunto = partes[0]
        itens = (partes[1] if len(partes) > 1 else "").upper()

        alvo = []
        if conjunto in ("1:*", "*"):
            alvo = list(range(1, len(MENSAGENS) + 1))
        else:
            for pedaco in conjunto.split(","):
                if ":" in pedaco:
                    a, b = pedaco.split(":", 1)
                    b = len(MENSAGENS) if b == "*" else int(b)
                    alvo += list(range(int(a), int(b) + 1))
                elif pedaco.isdigit():
                    alvo.append(int(pedaco))

        for n in alvo:
            if n < 1 or n > len(MENSAGENS):
                continue
            bruto = _b(MENSAGENS[n - 1])

            if "BODY.PEEK[HEADER" in itens or "BODY[HEADER" in itens:
                cabecalho = bruto.split(b"\r\n\r\n", 1)[0] + b"\r\n\r\n"
                # Metade com UID/FLAGS DEPOIS do literal: a RFC permite, e é
                # onde um cliente que só lê a primeira linha do item perde o
                # UID — deixando a lista inteira com links quebrados.
                if n % 2 == 0:
                    self._envia(f"* {n} FETCH (BODY[HEADER] ".encode())
                    self._envia(f"{{{len(cabecalho)}}}\r\n".encode())
                    self._envia(cabecalho)
                    self._envia(f" UID {n} FLAGS (\\Seen))\r\n".encode())
                else:
                    self._envia(f"* {n} FETCH (UID {n} FLAGS (\\Seen) BODY[HEADER] ".encode())
                    self._literal("", cabecalho)
            elif "RFC822.SIZE" in itens and "BODY" not in itens:
                self._envia(f"* {n} FETCH (UID {n} RFC822.SIZE {len(bruto)})\r\n".encode())
            else:
                # A RFC 3501 permite os data items em QUALQUER ordem. Metade
                # das mensagens vem com UID/FLAGS DEPOIS do literal — que é
                # como servidores reais costumam responder e como um cliente
                # que só lê a primeira linha do item quebra.
                if n % 2 == 0:
                    self._envia(f"* {n} FETCH (BODY[] ".encode())
                    self._envia(f"{{{len(bruto)}}}\r\n".encode())
                    self._envia(bruto)
                    self._envia(f" UID {n} FLAGS (\\Seen))\r\n".encode())
                else:
                    self._envia(f"* {n} FETCH (UID {n} FLAGS (\\Seen) BODY[] ".encode())
                    self._literal("", bruto)

        self._envia(f"{tag} OK FETCH concluído\r\n")


class Servidor(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True


if __name__ == "__main__":
    porta = int(sys.argv[1]) if len(sys.argv) > 1 else 10143
    with Servidor(("127.0.0.1", porta), Sessao) as s:
        print(f"IMAP de teste em 127.0.0.1:{porta} "
              f"(usuário {USUARIO}, senha {SENHA}, {len(MENSAGENS)} mensagens)",
              flush=True)
        s.serve_forever()
