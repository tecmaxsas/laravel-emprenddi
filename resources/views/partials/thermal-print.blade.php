{{--
    Cómo se imprime en una térmica, para todos los formatos de 80 mm.

    Un cabezal térmico no tiene tinta: quema puntos. Solo entiende «hay punto o
    no hay punto», así que cualquier gris lo aproxima con un patrón disperso y
    sale desvaído.

    El problema no es el color —los formatos ya usaban negro— sino el
    SUAVIZADO. El navegador dibuja los bordes de cada letra con píxeles
    intermedios para que se vean redondeados en pantalla; a 203 dpi, que es lo
    normal en estas impresoras, una letra fina es casi toda borde. El driver
    convierte esos grises en puntos sueltos y el resultado es el texto clarito
    y borroso. Por eso en un mismo tiquete la negrita sale oscura y el resto
    no: la negrita tiene suficiente relleno sólido como para sobrevivir.

    Los programas con los que el cliente compara no pasan por aquí: mandan
    texto en el modo nativo de la impresora, que usa la fuente del cabezal y
    no rasteriza nada. Imprimiendo HTML eso no es posible, así que lo que se
    hace es quitarle al navegador todo lo que suaviza.

    Vive en un solo archivo porque son cuatro formatos —comanda, precuenta,
    tiquete de venta y tiquete de parqueadero— y la regla es la misma. Escrita
    cuatro veces, se arregla en uno y los otros tres siguen saliendo claros,
    que es exactamente lo que acababa de pasar.
--}}
<style>
    @media print {
        /* 1. NEGRO PURO, sin herencias que se cuelen.
              `print-color-adjust` impide que el navegador «optimice» la
              impresión aclarando lo que considera decorativo. */
        * {
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
            color: #000 !important;
        }

        /* 2. SIN SUAVIZADO. Esta es la regla que faltaba.
              Cada letra se dibuja con píxeles enteros: o negro o blanco, sin
              bordes intermedios que el cabezal convierta en puntos sueltos. */
        * {
            -webkit-font-smoothing: none !important;
            -moz-osx-font-smoothing: grayscale;
            text-rendering: geometricPrecision !important;
        }

        /* 3. TRAZO CON CUERPO. Una fuente fina deja huecos entre punto y
              punto: lo que en pantalla se ve elegante, impreso se ve roto. */
        body { font-weight: 600 !important; }
        b, strong, th, .bold { font-weight: 800 !important; }

        /* 4. SEPARADORES SÓLIDOS. El punteado depende de que el driver lo
              dibuje bien, y a esta resolución suele desaparecer a trozos. */
        hr, .sep, .sep-strong {
            border-top-style: solid !important;
            border-top-color: #000 !important;
        }

        /* 5. Los controles de pantalla no se imprimen. Cada formato ya los
              oculta, pero aquí queda la red por si alguno se olvida: un
              botón impreso en un tiquete es papel desperdiciado. */
        .screen-only, .actions, button { display: none !important; }
    }
</style>
