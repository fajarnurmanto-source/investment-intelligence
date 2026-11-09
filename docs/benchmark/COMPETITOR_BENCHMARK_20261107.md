# II Competitor Benchmark and Implemented Gaps

Evidence checked 9 November 2026 against official product/help pages. This benchmark informs implementation; it is not a claim of parity.

| Platform | Relevant benchmark capability | II gap at baseline | Implemented in this branch | Remaining limitation |
|---|---|---|---|---|
| TradingView | Multi-filter fundamental/technical screener, saved screens, table/chart modes, CSV export, alerts | No cross-issuer screen | Fundamental Screener with margin/growth/ROE/OCF filters, transparent quality score, sorting, CSV export | Only user-imported issuers; no licensed global universe, saved screens, or alerts yet |
| Koyfin | Portfolio holdings, exposure views, weighted summaries, customizable dashboards | Portfolio comparison lacked tail-risk statistics | Portfolio Sandbox now includes historical VaR 95%, CVaR 95%, correlation, drawdown, concentration, and source-quality panels | No lots, FX conversion, sectors/countries, or live P/L |
| TIKR | Global equity screener, long financial history, valuation builder, estimates and transcripts | No idea-generation surface or valuation builder | Fundamental screen directly reuses II normalized issuer snapshots | Valuation and estimates require rights-cleared data before implementation |
| Simply Wall St | Five-dimension visual summary (valuation, future growth, past performance, financial health, dividends) and portfolio aggregation | Fundamental review was single-company only | Transparent 0–100 profitability/growth/ROE/cash score across imported issuers | Does not imitate proprietary Snowflake; no fair value/future/dividend dimensions yet |
| Portfolio Visualizer | Backtesting, Monte Carlo, optimization, efficient frontier, CVaR/risk parity | Portfolio analytics lacked tail-loss measures | Non-parametric historical VaR/CVaR integrated with existing portfolio scenario and quality checks | No optimizer or Monte Carlo; those require stronger data-frequency and model validation |

## Selected high-value gaps

1. **Idea generation:** multi-factor fundamental screener and CSV export.
2. **Portfolio/risk analytics:** historical VaR/CVaR using mature PHP statistics, alongside correlation, drawdown, and concentration.
3. **Macro/data/reporting:** real FRED series with provenance, normalization, analytics, dashboard, and CSV report.

## Official reference pages

- TradingView Stock Screener: https://www.tradingview.com/support/solutions/43000718866-tradingview-stock-screener-trade-smarter-not-harder/
- Koyfin portfolio tools: https://www.koyfin.com/help/my-portfolios/
- TIKR global screener: https://support.tikr.com/hc/en-us/articles/5365410657563-How-to-use-the-powerful-Global-Equity-Screener
- Simply Wall St portfolio analysis: https://support.simplywall.st/hc/en-us/articles/9423775242383-Understanding-the-Portfolio-Returns-Analysis-Calculations
- Portfolio Visualizer tools: https://www.portfoliovisualizer.com/
- Portfolio Visualizer optimization: https://www.portfoliovisualizer.com/optimize-portfolio
