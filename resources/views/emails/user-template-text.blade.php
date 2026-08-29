{{--
  The PLAIN-TEXT part of a campaign email (studio campaigns compile one
  alongside the HTML; spam filters and text-only clients both thank us).

  SECURITY: $renderedText has ALREADY been substituted by TemplateRenderer via
  strtr() (NEVER Blade), and it is text/plain — no HTML context exists here, so
  escaping would only corrupt honest characters (& into &amp;). Echo, nothing
  else.
--}}{!! $renderedText !!}
