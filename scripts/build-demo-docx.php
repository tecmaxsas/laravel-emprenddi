<?php

/**
 * Genera el instructivo del demo de perfumeria en formato Word.
 *
 * Se arma el .docx a mano —es un ZIP con XML de OOXML dentro— porque el
 * proyecto no trae PhpWord ni el contenedor tiene pandoc, y agregar una
 * dependencia entera para un documento no se justifica.
 *
 *   docker exec emprenddi_app php scripts/build-demo-docx.php
 */
class DocxBuilder
{
    private string $cuerpo = '';

    public function titulo(string $texto): self
    {
        return $this->parrafo($texto, estilo: 'Title');
    }

    public function h1(string $texto): self
    {
        return $this->parrafo($texto, estilo: 'Heading1');
    }

    public function h2(string $texto): self
    {
        return $this->parrafo($texto, estilo: 'Heading2');
    }

    /** Texto con **negrita** entre asteriscos dobles. */
    public function p(string $texto, string $estilo = 'Normal'): self
    {
        return $this->parrafo($texto, estilo: $estilo);
    }

    public function vineta(string $texto): self
    {
        return $this->parrafo($texto, estilo: 'ListParagraph', vineta: true);
    }

    /** Bloque de comando, en monoespaciada sobre fondo gris. */
    public function comando(string $texto): self
    {
        foreach (explode("\n", $texto) as $linea) {
            $this->cuerpo .= '<w:p><w:pPr><w:pStyle w:val="Codigo"/>'
                .'<w:shd w:val="clear" w:fill="F1F3F5"/></w:pPr>'
                .'<w:r><w:rPr><w:rFonts w:ascii="Consolas" w:hAnsi="Consolas"/><w:sz w:val="18"/></w:rPr>'
                .'<w:t xml:space="preserve">'.$this->esc($linea).'</w:t></w:r></w:p>';
        }

        return $this;
    }

    /**
     * @param  list<string>  $encabezados
     * @param  list<list<string>>  $filas
     */
    public function tabla(array $encabezados, array $filas): self
    {
        $anchoTotal = 9000;
        $ancho = (int) floor($anchoTotal / max(1, count($encabezados)));

        $xml = '<w:tbl><w:tblPr><w:tblStyle w:val="TableGrid"/>'
            .'<w:tblW w:w="'.$anchoTotal.'" w:type="dxa"/>'
            .'<w:tblBorders>'
            .'<w:top w:val="single" w:sz="4" w:color="D0D5DD"/>'
            .'<w:left w:val="single" w:sz="4" w:color="D0D5DD"/>'
            .'<w:bottom w:val="single" w:sz="4" w:color="D0D5DD"/>'
            .'<w:right w:val="single" w:sz="4" w:color="D0D5DD"/>'
            .'<w:insideH w:val="single" w:sz="4" w:color="D0D5DD"/>'
            .'<w:insideV w:val="single" w:sz="4" w:color="D0D5DD"/>'
            .'</w:tblBorders></w:tblPr>';

        $xml .= '<w:tr><w:trPr><w:tblHeader/></w:trPr>';
        foreach ($encabezados as $celda) {
            $xml .= '<w:tc><w:tcPr><w:tcW w:w="'.$ancho.'" w:type="dxa"/>'
                .'<w:shd w:val="clear" w:fill="1F2937"/></w:tcPr>'
                .'<w:p><w:pPr><w:spacing w:before="40" w:after="40"/></w:pPr>'
                .'<w:r><w:rPr><w:b/><w:color w:val="FFFFFF"/><w:sz w:val="18"/></w:rPr>'
                .'<w:t xml:space="preserve">'.$this->esc($celda).'</w:t></w:r></w:p></w:tc>';
        }
        $xml .= '</w:tr>';

        foreach ($filas as $i => $fila) {
            $fondo = $i % 2 === 0 ? 'FFFFFF' : 'F8F9FA';
            $xml .= '<w:tr>';
            foreach ($fila as $celda) {
                $xml .= '<w:tc><w:tcPr><w:tcW w:w="'.$ancho.'" w:type="dxa"/>'
                    .'<w:shd w:val="clear" w:fill="'.$fondo.'"/></w:tcPr>'
                    .'<w:p><w:pPr><w:spacing w:before="40" w:after="40"/></w:pPr>'
                    .$this->runs($celda, 18).'</w:p></w:tc>';
            }
            $xml .= '</w:tr>';
        }

        $this->cuerpo .= $xml.'</w:tbl><w:p/>';

        return $this;
    }

    public function saltoDePagina(): self
    {
        $this->cuerpo .= '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';

        return $this;
    }

    public function guardar(string $ruta): void
    {
        @unlink($ruta);

        $zip = new ZipArchive;
        if ($zip->open($ruta, ZipArchive::CREATE) !== true) {
            throw new RuntimeException("No se pudo crear {$ruta}");
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rels());
        $zip->addFromString('word/_rels/document.xml.rels', $this->documentRels());
        $zip->addFromString('word/styles.xml', $this->styles());
        $zip->addFromString('word/numbering.xml', $this->numbering());
        $zip->addFromString('word/document.xml', $this->document());

        $zip->close();
    }

    private function parrafo(string $texto, string $estilo, bool $vineta = false): self
    {
        $pPr = '<w:pStyle w:val="'.$estilo.'"/>';

        if ($vineta) {
            $pPr .= '<w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr>';
        }

        $this->cuerpo .= '<w:p><w:pPr>'.$pPr.'</w:pPr>'.$this->runs($texto).'</w:p>';

        return $this;
    }

    /** Convierte **negrita** y `codigo` en runs con formato. */
    private function runs(string $texto, ?int $tam = null): string
    {
        $partes = preg_split('/(\*\*.+?\*\*|`.+?`)/u', $texto, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $xml = '';

        foreach ($partes as $parte) {
            $rPr = '';
            $contenido = $parte;

            if (str_starts_with($parte, '**') && str_ends_with($parte, '**')) {
                $contenido = mb_substr($parte, 2, -2);
                $rPr .= '<w:b/>';
            } elseif (str_starts_with($parte, '`') && str_ends_with($parte, '`')) {
                $contenido = mb_substr($parte, 1, -1);
                $rPr .= '<w:rFonts w:ascii="Consolas" w:hAnsi="Consolas"/><w:color w:val="B91C1C"/>';
            }

            if ($tam !== null) {
                $rPr .= '<w:sz w:val="'.$tam.'"/>';
            }

            $xml .= '<w:r>'.($rPr !== '' ? '<w:rPr>'.$rPr.'</w:rPr>' : '')
                .'<w:t xml:space="preserve">'.$this->esc($contenido).'</w:t></w:r>';
        }

        return $xml;
    }

    private function esc(string $t): string
    {
        return htmlspecialchars($t, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function document(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:body>'.$this->cuerpo
            .'<w:sectPr><w:pgSz w:w="11906" w:h="16838"/>'
            .'<w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134"/></w:sectPr>'
            .'</w:body></w:document>';
    }

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            .'<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            .'<Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/>'
            .'</Types>';
    }

    private function rels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            .'</Relationships>';
    }

    private function documentRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering" Target="numbering.xml"/>'
            .'</Relationships>';
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:docDefaults><w:rPrDefault><w:rPr>'
            .'<w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:sz w:val="21"/></w:rPr></w:rPrDefault>'
            .'<w:pPrDefault><w:pPr><w:spacing w:after="140" w:line="276" w:lineRule="auto"/></w:pPr></w:pPrDefault>'
            .'</w:docDefaults>'
            .'<w:style w:type="paragraph" w:styleId="Normal" w:default="1"><w:name w:val="Normal"/></w:style>'
            .'<w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/>'
            .'<w:pPr><w:spacing w:after="80"/></w:pPr>'
            .'<w:rPr><w:b/><w:sz w:val="52"/><w:color w:val="111827"/></w:rPr></w:style>'
            .'<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/>'
            .'<w:pPr><w:spacing w:before="360" w:after="120"/>'
            .'<w:pBdr><w:bottom w:val="single" w:sz="8" w:color="7C3AED"/></w:pBdr></w:pPr>'
            .'<w:rPr><w:b/><w:sz w:val="30"/><w:color w:val="5B21B6"/></w:rPr></w:style>'
            .'<w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/>'
            .'<w:pPr><w:spacing w:before="240" w:after="100"/></w:pPr>'
            .'<w:rPr><w:b/><w:sz w:val="24"/><w:color w:val="111827"/></w:rPr></w:style>'
            .'<w:style w:type="paragraph" w:styleId="ListParagraph"><w:name w:val="List Paragraph"/>'
            .'<w:pPr><w:spacing w:after="60"/><w:ind w:left="360"/></w:pPr></w:style>'
            .'<w:style w:type="paragraph" w:styleId="Codigo"><w:name w:val="Codigo"/>'
            .'<w:pPr><w:spacing w:after="0" w:line="240" w:lineRule="auto"/><w:ind w:left="120"/></w:pPr></w:style>'
            .'<w:style w:type="table" w:styleId="TableGrid"><w:name w:val="Table Grid"/></w:style>'
            .'</w:styles>';
    }

    private function numbering(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:numbering xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:abstractNum w:abstractNumId="0"><w:lvl w:ilvl="0">'
            .'<w:numFmt w:val="bullet"/><w:lvlText w:val="•"/>'
            .'<w:pPr><w:ind w:left="360" w:hanging="200"/></w:pPr></w:lvl></w:abstractNum>'
            .'<w:num w:numId="1"><w:abstractNumId w:val="0"/></w:num>'
            .'</w:numbering>';
    }
}

require __DIR__.'/demo-docx-contenido.php';
