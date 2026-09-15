"""A7 Org & Security console (Hostinger BFF).

A thin, admin-only web console rendered inside a Bitrix24 left-menu iframe. It
reads the company structure and applies team + CRM-security changes by calling the
signed Org API on the Bitrix host. It never talks to Bitrix or the database
directly.
"""

__version__ = "1.0.0"
