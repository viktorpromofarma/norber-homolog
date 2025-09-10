# Integração Promofarma - LG

## Objetivo

_Este projeto foi desenvolvido com a intenção de automatizar e facilitar a consulta dos dados de marcação de pontos de funcionários da promofarma_

# Tecnologias Utilizadas

_Este projeto utiliza para a integração a api Norber, o framework PHP Laravel, foi utilizado o Docker para criar o ambiente de desenvolvimento e MSSQL para o banco de dados_

-   `Ambiente docker padrão`: https://github.com/Promofarma/Docker-Alpine3.20-PHP.git

# Como utilizar a integração

## Configuração do ambiente

Para a configuração do ambiente é necessário seguir os passos abaixo, assim você poderá executar a integração localmente:

OBS: Todo o ambiente de desenvolvimento foi criado em linux base DEB utilizando WSL, caso sua máquina não possuir uma vm ou wsl, faça a instalação seguindo o manuais/links abaixo.

Instalar Ubuntu via VM : https://4linux.com.br/como-instalar-o-linux/

Instalar Ubuntu via WSL : https://learn.microsoft.com/pt-br/windows/wsl/install

1. **Clonar esse repositório**

    Faça o clone desse repositório em sua máquina, de preferência com o nome padrão norber_api.

2. **Iniciando o projeto**

    Execute o comando _docker compose up -d --b_ no terminal no diretório do projeto.

3. **Configuração do ENV**

    Após a criação do container, acesse o diretório com o comando cd, após isso execute o comando **cp .env.example .env**,
    assim será criado o arquivo .env, adicione as credencias do banco **PROMOFARMA_API** na configuração de banco de dados.
    Existem 2 campos necessários dentro do ENV que precisam estar preenchidos que são API_USER e API_PASSWORD, esses campos são as credenciais da API (Se não possuir entre em contato pelo email com viktor.santos@promofarma.com.br ou mauri@promofarma.com.br)

# Comandos

Lista dos principais comandos utilizados no projeto:

_Ao receber uma lista de registros de conceitos diferentes, para compor este filtro de contratos através do
conceito informado, o método deverá seguir a ordem hierárquica, agrupando os conceitos na seguinte ordem:_

• 1º Empresa;

• 2º Unidade Organizacional;

• 3º Matrícula do contrato.

_Código Externo: Código do conceito informado na tag anterior. Sendo código da
empresa, código da unidade organizacional ou matrícula do contrato_

**Para executar cada comando é necessário digitar no terminal php artisan**

-   ### Resgatar o token:
-   `Servidor de produção` : https://prd-pt1.lg.com.br/NorberApi/api/autenticacao/autenticar
-   `Servidor de Homologação` : https://hml-pt1.lg.com.br/NorberApi/api/autenticacao/autenticar
-   `COMANDO DA APLICAÇÃO` : **php artisan norber:resgatar-token**

-   ### Saldo de banco de horas:
-   `Servidor de produção` : https://prd-pt1.lg.com.br/NorberApi/api/banco-de-horas/listar-saldo-v2
-   `Servidor de Homologação` : https://hml-pt1.lg.com.br/NorberApi/api/banco-de-horas/listar-saldo-v2
-   `COMANDO DA APLICAÇÃO` : **php artisan norber:listar-saldo --MesAnoReferencia="YYYY-mm" --Conceito="NUMBER" --CodigoExterno="NUMBER"**

-   ### Retorno das marcações de ponto:
-   `Servidor de produção` : https://prd-pt1.lg.com.br/NorberApi/api/banco-de-horas/listar-saldo-v2
-   `Servidor de Homologação` : https://hml-pt1.lg.com.br/NorberApi/api/banco-de-horas/listar-saldo-v2
-   `COMANDO DA APLICAÇÃO` : **php artisan norber:retornar-marcacoes --start-date="YYYY-MM-DD" --end-date="YYYY-MM-DD" --Conceito="NUMBER" --CodigoExterno="NUMBER"**

-   ### Retorno as ocorrencias na marcação do ponto:
-   `Servidor de produção` : https://prd-pt1.lg.com.br/NorberApi/api/banco-de-horas/listar-saldo-v2
-   `Servidor de Homologação` : https://hml-pt1.lg.com.br/NorberApi/api/banco-de-horas/listar-saldo-v2
-   `COMANDO DA APLICAÇÃO` : **php artisan norber:retorna-ocorrencia-ausencia --start-date="YYYY-MM-DD" --end-date="YYYY-MM-DD" --Conceito="NUMBER" --CodigoExterno="NUMBER"**

## Dúvidas

Para dúvidas sobre o projeto, entre em contato através de:

-   **Desenvolvimento**: `Viktor Santos Moitinho`
-   **Email**: viktor.santos@promofarma.com.br

-   **Recursos Humanos**:`João Vitor Avoglia de Almeida`
-   **Email**: joao.vitor@promofarma.com.br

## Referências

-   **Laravel** : https://laravel.com/
-   **Docker** : https://docs.docker.com/
-   **API Norber** : https://prd-pt1.lg.com.br/NorberApi/swagger/ui/index
-   **Instalação via VMW** : https://4linux.com.br
-   **Instalação via WSL** : https://learn.microsoft.com
