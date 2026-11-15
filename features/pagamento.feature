# language: pt
Funcionalidade: Cobrança no Mercado Pago
  A preferência nasce no sandbox. Estes cenários não falam com o Mercado Pago
  de verdade: a resposta HTTP é substituída antes da chamada.

  Cenário: Criar a preferência de um orçamento
    Dado um orçamento gerado
    Quando eu crio a cobrança desse orçamento
    Então a resposta deve ter o código "201"
    E o status da resposta é "pendente"
    E a resposta traz o link de checkout

  Cenário: Cobrar de novo reaproveita a preferência pendente
    Dado um orçamento gerado
    Quando eu crio a cobrança desse orçamento
    E eu crio a cobrança desse orçamento de novo
    Então a resposta deve ter o código "200"
    E existe um único pagamento

  Cenário: Consultar um pagamento aprovado no sandbox
    Dado um pagamento pendente
    Quando o Mercado Pago informa o pagamento como aprovado
    E eu consulto esse pagamento
    Então a resposta deve ter o código "200"
    E o valor pago é "103"

  Cenário: Sem token a cobrança é recusada
    Dado um orçamento gerado
    E o token do Mercado Pago não está configurado
    Quando eu crio a cobrança desse orçamento
    Então a resposta deve ter o código "503"
