import { useEffect, useMemo, useState } from "@wordpress/element";
import { __ } from "@wordpress/i18n";
import apiFetch from "@wordpress/api-fetch";
import { Button, Notice, Spinner } from "@wordpress/components";

const { feedFilterMode: initialMode = "TAG" } = window.mdfcforwcAdmin || {};

const PER_PAGE = 25;

export default function Feed() {
  const [mode, setMode] = useState(initialMode);
  const [products, setProducts] = useState([]);
  const [inFeedCount, setInFeedCount] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [switching, setSwitching] = useState(false);
  const [manageMode, setManageMode] = useState(false);
  const [manageProducts, setManageProducts] = useState([]);
  const [manageSearch, setManageSearch] = useState("");
  const [feedSearch, setFeedSearch] = useState("");
  const [feedPage, setFeedPage] = useState(1);
  const [feedTotalPages, setFeedTotalPages] = useState(1);
  const [managePage, setManagePage] = useState(1);
  const [manageTotalPages, setManageTotalPages] = useState(1);
  const [manageTotal, setManageTotal] = useState(0);
  const [manageLoading, setManageLoading] = useState(false);
  const [sortField, setSortField] = useState("name");
  const [sortDir, setSortDir] = useState("asc");
  const [manageSortField, setManageSortField] = useState("name");
  const [manageSortDir, setManageSortDir] = useState("asc");
  const [isInitialized, setIsInitialized] = useState(false);
  const [feedLoading, setFeedLoading] = useState(false);
  const [feedError, setFeedError] = useState(null);

  const isServerList = mode === "SERVERLIST";

  const fetchProducts = async (page = 1, search = feedSearch) => {
    try {
      if (!isInitialized) {
        setLoading(true);
      } else {
        setFeedLoading(true);
      }
      setFeedError(null);
      const data = await apiFetch({
        path: `/mdfcforwc/v1/admin/products?page=${page}&per_page=${PER_PAGE}${
          search ? `&search=${encodeURIComponent(search)}` : ""
        }`,
      });
      setProducts(data.products || []);
      setInFeedCount(data.inFeedCount ?? data.total ?? 0);
      setMode(data.feedFilterMode || mode);
      setFeedPage(page);
      setFeedTotalPages(data.total_pages || 1);
    } catch {
      setFeedError(
        __(
          "Failed to load the feed data.",
          "marques-de-france-connector-for-woocommerce",
        ),
      );
    } finally {
      setLoading(false);
      setFeedLoading(false);
      setIsInitialized(true);
    }
  };

  const fetchManageProducts = async (page = 1, search = "") => {
    try {
      setManageLoading(true);
      const data = await apiFetch({
        path: `/mdfcforwc/v1/admin/all-products?page=${page}&per_page=${PER_PAGE}${
          search ? `&search=${encodeURIComponent(search)}` : ""
        }`,
      });
      setManageProducts(data.products || []);
      setManageTotal(data.total || 0);
      setManageTotalPages(data.total_pages || 1);
      setManagePage(page);
      setInFeedCount(data.inFeedCount ?? inFeedCount);
    } catch {
      setError(
        __(
          "Failed to load the product selection list.",
          "marques-de-france-connector-for-woocommerce",
        ),
      );
    } finally {
      setManageLoading(false);
    }
  };

  useEffect(() => {
    fetchProducts();
  }, []);

  const handleModeSwitch = async (nextMode) => {
    if (
      !window.confirm(
        __(
          "Changing the feed mode will update how products are selected. Continue?",
          "marques-de-france-connector-for-woocommerce",
        ),
      )
    ) {
      return;
    }

    try {
      setSwitching(true);
      await apiFetch({
        path: "/mdfcforwc/v1/admin/feed-settings",
        method: "PATCH",
        data: { feedFilterMode: nextMode },
      });
      setMode(nextMode);
      await fetchProducts(1, feedSearch);
    } catch {
      setError(
        __(
          "Unable to update the feed mode.",
          "marques-de-france-connector-for-woocommerce",
        ),
      );
    } finally {
      setSwitching(false);
    }
  };

  const openManageMode = () => {
    setManageMode(true);
    setManagePage(1);
    setManageSearch("");
    fetchManageProducts(1, "");
  };

  const closeManageMode = async () => {
    setManageMode(false);
    await fetchProducts(feedPage, feedSearch);
  };

  const toggleProduct = async (productId, currentlyInFeed) => {
    setManageProducts((current) =>
      current.map((product) =>
        product.id === productId
          ? { ...product, inFeed: !currentlyInFeed }
          : product,
      ),
    );
    setInFeedCount((current) => current + (currentlyInFeed ? -1 : 1));

    try {
      if (currentlyInFeed) {
        await apiFetch({
          path: `/mdfcforwc/v1/admin/feed-products/${productId}`,
          method: "DELETE",
        });
      } else {
        await apiFetch({
          path: "/mdfcforwc/v1/admin/feed-products",
          method: "POST",
          data: { productId },
        });
      }
    } catch {
      setManageProducts((current) =>
        current.map((product) =>
          product.id === productId
            ? { ...product, inFeed: currentlyInFeed }
            : product,
        ),
      );
      setInFeedCount((current) => current + (currentlyInFeed ? 1 : -1));
    }
  };

  const parsePriceValue = (value) => {
    if (typeof value === "number") {
      return Number.isFinite(value) ? value : 0;
    }

    const raw = String(value ?? "")
      .replace(/&nbsp;|&#160;/g, " ")
      .replace(/\s+/g, "");

    const matches = raw.match(/-?\d[\d.,]*/g);
    if (!matches || matches.length === 0) {
      return 0;
    }

    // WooCommerce price_html for discounted products often includes old then final price.
    // We sort by the last displayed numeric value, which corresponds to the final price.
    const candidate = matches[matches.length - 1]
      .replace(/\.(?=\d{3}(\D|$))/g, "")
      .replace(/,(?=\d{3}(\D|$))/g, "")
      .replace(",", ".");

    const numeric = Number(candidate);
    return Number.isFinite(numeric) ? numeric : 0;
  };

  const getSortablePrice = (product) => {
    const preferredValues = [
      product.final_price,
      product.sale_price,
      product.current_price,
      product.price,
    ];

    for (const value of preferredValues) {
      if (value === undefined || value === null || value === "") {
        continue;
      }

      return parsePriceValue(value);
    }

    return parsePriceValue(product.price_html);
  };

  const STATUS_COLORS = {
    confirmed: { background: "#00a32a", color: "#fff" },
    cancelled: { background: "#d63638", color: "#fff" },
    refunded: { background: "#9ea3a8", color: "#fff" },
    pending: { background: "#f0c33c", color: "#2c3338" },
  };

  const chipStyle = (value) => {
    const label = String(value || "").toLowerCase();

    if (
      label.includes("out of stock") ||
      label.includes("out-of-stock") ||
      label.includes("rupture") ||
      label.includes("indisponible") ||
      label.includes("unavailable") ||
      label.includes("cancelled")
    ) {
      return STATUS_COLORS.cancelled;
    }

    if (
      label.includes("in stock") ||
      label.includes("en stock") ||
      label.includes("disponible") ||
      label.includes("available") ||
      label.includes("active") ||
      label.includes("confirmed")
    ) {
      return STATUS_COLORS.confirmed;
    }

    if (
      label.includes("inactive") ||
      label.includes("draft") ||
      label.includes("private") ||
      label.includes("pending")
    ) {
      return STATUS_COLORS.pending;
    }

    return STATUS_COLORS.refunded;
  };

  const handleSort = (field, scope = "feed") => {
    if (scope === "manage") {
      setManageSortField(field);
      setManageSortDir((current) =>
        current === "asc" && manageSortField === field ? "desc" : "asc",
      );
      return;
    }

    setSortField(field);
    setSortDir((current) =>
      current === "asc" && sortField === field ? "desc" : "asc",
    );
  };

  const sortIndicator = (field, scope = "feed") => {
    const activeField = scope === "manage" ? manageSortField : sortField;
    const activeDir = scope === "manage" ? manageSortDir : sortDir;
    if (field !== activeField) {
      return " ↕";
    }
    return activeDir === "asc" ? " ↑" : " ↓";
  };

  const sortedProducts = useMemo(() => {
    const items = [...products];
    items.sort((a, b) => {
      if (sortField === "price") {
        const diff = getSortablePrice(a) - getSortablePrice(b);
        return sortDir === "asc" ? diff : -diff;
      }
      const left = String(a[sortField] ?? "").toLowerCase();
      const right = String(b[sortField] ?? "").toLowerCase();
      return sortDir === "asc"
        ? left.localeCompare(right)
        : right.localeCompare(left);
    });
    return items;
  }, [products, sortField, sortDir]);

  const sortedManageProducts = useMemo(() => {
    const items = [...manageProducts];
    items.sort((a, b) => {
      if (manageSortField === "price") {
        const diff = getSortablePrice(a) - getSortablePrice(b);
        return manageSortDir === "asc" ? diff : -diff;
      }
      const left = String(a[manageSortField] ?? "").toLowerCase();
      const right = String(b[manageSortField] ?? "").toLowerCase();
      return manageSortDir === "asc"
        ? left.localeCompare(right)
        : right.localeCompare(left);
    });
    return items;
  }, [manageProducts, manageSortField, manageSortDir]);

  const summaryText = useMemo(() => {
    if (isServerList) {
      return __(
        "Via manual selection from this app",
        "marques-de-france-connector-for-woocommerce",
      );
    }
    return __(
      "Via the product tag field",
      "marques-de-france-connector-for-woocommerce",
    );
  }, [isServerList]);

  const renderPrice = (product) => {
    const value = product.price_html || product.price || "—";

    if (typeof value === "string" && value.includes("<")) {
      return <span dangerouslySetInnerHTML={{ __html: value }} />;
    }

    return value;
  };

  let tableContent;

  if (manageMode) {
    tableContent = (
      <div>
        <form
          className="mdf-filters"
          onSubmit={(event) => {
            event.preventDefault();
            fetchManageProducts(1, manageSearch);
          }}
        >
          <input
            type="search"
            className="mdf-input"
            style={{ flex: 1, minWidth: 180 }}
            placeholder={__(
              "Search products or brands",
              "marques-de-france-connector-for-woocommerce",
            )}
            value={manageSearch}
            onChange={(event) => setManageSearch(event.target.value)}
          />
          <Button
            type="submit"
            variant="secondary"
            style={{ backgroundColor: "#fff", minHeight: 40, height: 40 }}
            onClick={() => fetchManageProducts(1, manageSearch)}
          >
            {__("Search", "marques-de-france-connector-for-woocommerce")}
          </Button>
          <Button
            type="button"
            variant="secondary"
            style={{ backgroundColor: "#fff", minHeight: 40, height: 40 }}
            onClick={closeManageMode}
          >
            {__("Back to feed", "marques-de-france-connector-for-woocommerce")}
          </Button>
        </form>

        {manageLoading ? (
          <div className="mdf-loading">
            <Spinner />
            {__(
              "Loading products…",
              "marques-de-france-connector-for-woocommerce",
            )}
          </div>
        ) : (
          <>
            <div className="mdf-table-wrap">
              <table className="mdf-table">
                <thead>
                  <tr>
                    <th style={{ width: 46 }}></th>
                    <th>
                      <button
                        type="button"
                        className={`mdf-sort-btn${
                          manageSortField === "name"
                            ? " mdf-sort-btn--active"
                            : ""
                        }`}
                        onClick={() => handleSort("name", "manage")}
                      >
                        {__(
                          "Product",
                          "marques-de-france-connector-for-woocommerce",
                        )}
                        {sortIndicator("name", "manage")}
                      </button>
                    </th>
                    <th>
                      <button
                        type="button"
                        className={`mdf-sort-btn${
                          manageSortField === "brand"
                            ? " mdf-sort-btn--active"
                            : ""
                        }`}
                        onClick={() => handleSort("brand", "manage")}
                      >
                        {__(
                          "Brand",
                          "marques-de-france-connector-for-woocommerce",
                        )}
                        {sortIndicator("brand", "manage")}
                      </button>
                    </th>
                    <th>
                      <button
                        type="button"
                        className={`mdf-sort-btn${
                          manageSortField === "price"
                            ? " mdf-sort-btn--active"
                            : ""
                        }`}
                        onClick={() => handleSort("price", "manage")}
                      >
                        {__(
                          "Price",
                          "marques-de-france-connector-for-woocommerce",
                        )}
                        {sortIndicator("price", "manage")}
                      </button>
                    </th>
                    <th>
                      {__(
                        "Availability",
                        "marques-de-france-connector-for-woocommerce",
                      )}
                    </th>
                    <th>
                      {__(
                        "Status",
                        "marques-de-france-connector-for-woocommerce",
                      )}
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {sortedManageProducts.map((product) => (
                    <tr key={product.id}>
                      <td>
                        <input
                          type="checkbox"
                          checked={Boolean(product.inFeed)}
                          onChange={() =>
                            toggleProduct(product.id, Boolean(product.inFeed))
                          }
                        />
                      </td>
                      <td>
                        <div className="mdf-feed-product-cell">
                          <img
                            src={product.image}
                            alt=""
                            className="mdf-feed-thumb"
                          />
                          <div>
                            <span>{product.name}</span>
                          </div>
                        </div>
                      </td>
                      <td>{product.brand || "—"}</td>
                      <td>{renderPrice(product)}</td>
                      <td>
                        <span
                          style={{
                            background: chipStyle(product.availability)
                              .background,
                            color: chipStyle(product.availability).color,
                            borderRadius: 3,
                            display: "inline-block",
                            fontSize: 11,
                            fontWeight: 600,
                            padding: "2px 8px",
                            textTransform: "capitalize",
                          }}
                        >
                          {product.availability ||
                            __(
                              "Unknown",
                              "marques-de-france-connector-for-woocommerce",
                            )}
                        </span>
                      </td>
                      <td>
                        <span
                          style={{
                            background: chipStyle(
                              product.status || product.feed_status,
                            ).background,
                            borderRadius: 3,
                            color: chipStyle(
                              product.status || product.feed_status,
                            ).color,
                            display: "inline-block",
                            fontSize: 11,
                            fontWeight: 600,
                            padding: "2px 8px",
                            textTransform: "capitalize",
                          }}
                        >
                          {product.status ||
                            product.feed_status ||
                            __(
                              "Active",
                              "marques-de-france-connector-for-woocommerce",
                            )}
                        </span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            {manageTotalPages > 1 && (
              <div className="mdf-pagination">
                <span className="mdf-pagination__info">
                  {__("Page", "marques-de-france-connector-for-woocommerce")}{" "}
                  {managePage} / {manageTotalPages}
                </span>
                <Button
                  variant="secondary"
                  style={{ backgroundColor: "#fff" }}
                  disabled={managePage <= 1}
                  onClick={() =>
                    fetchManageProducts(managePage - 1, manageSearch)
                  }
                >
                  {__(
                    "Previous",
                    "marques-de-france-connector-for-woocommerce",
                  )}
                </Button>
                <Button
                  variant="secondary"
                  style={{ backgroundColor: "#fff" }}
                  disabled={managePage >= manageTotalPages}
                  onClick={() =>
                    fetchManageProducts(managePage + 1, manageSearch)
                  }
                >
                  {__("Next", "marques-de-france-connector-for-woocommerce")}
                </Button>
              </div>
            )}
          </>
        )}
      </div>
    );
  } else {
    tableContent = (
      <>
        <form
          className="mdf-filters"
          onSubmit={(event) => {
            event.preventDefault();
            fetchProducts(1, feedSearch);
          }}
        >
          <input
            type="search"
            className="mdf-input"
            style={{ flex: 1, minWidth: 180 }}
            placeholder={__(
              "Search products or brands",
              "marques-de-france-connector-for-woocommerce",
            )}
            value={feedSearch}
            onChange={(event) => setFeedSearch(event.target.value)}
          />
          <Button
            type="submit"
            variant="secondary"
            style={{ backgroundColor: "#fff", minHeight: 40, height: 40 }}
            onClick={() => fetchProducts(1, feedSearch)}
          >
            {__("Search", "marques-de-france-connector-for-woocommerce")}
          </Button>
          {isServerList && (
            <Button
              type="button"
              variant="primary"
              style={{ minHeight: 40, height: 40 }}
              onClick={openManageMode}
            >
              {__(
                "Modify selection",
                "marques-de-france-connector-for-woocommerce",
              )}
            </Button>
          )}
        </form>
        <div className="mdf-table-wrap">
          <table className="mdf-table">
            <thead>
              <tr>
                <th>
                  <button
                    type="button"
                    className={`mdf-sort-btn${
                      sortField === "name" ? " mdf-sort-btn--active" : ""
                    }`}
                    onClick={() => handleSort("name")}
                  >
                    {__(
                      "Product",
                      "marques-de-france-connector-for-woocommerce",
                    )}
                    {sortIndicator("name")}
                  </button>
                </th>
                <th>
                  <button
                    type="button"
                    className={`mdf-sort-btn${
                      sortField === "brand" ? " mdf-sort-btn--active" : ""
                    }`}
                    onClick={() => handleSort("brand")}
                  >
                    {__("Brand", "marques-de-france-connector-for-woocommerce")}
                    {sortIndicator("brand")}
                  </button>
                </th>
                <th>
                  <button
                    type="button"
                    className={`mdf-sort-btn${
                      sortField === "price" ? " mdf-sort-btn--active" : ""
                    }`}
                    onClick={() => handleSort("price")}
                  >
                    {__("Price", "marques-de-france-connector-for-woocommerce")}
                    {sortIndicator("price")}
                  </button>
                </th>
                <th>
                  {__(
                    "Availability",
                    "marques-de-france-connector-for-woocommerce",
                  )}
                </th>
                <th>
                  {__("Status", "marques-de-france-connector-for-woocommerce")}
                </th>
              </tr>
            </thead>
            <tbody>
              {feedLoading ? (
                <tr>
                  <td colSpan={5} className="mdf-table__loading">
                    {__(
                      "Loading…",
                      "marques-de-france-connector-for-woocommerce",
                    )}
                  </td>
                </tr>
              ) : feedError ? (
                <tr>
                  <td colSpan={5}>
                    <div className="mdf-error">{feedError}</div>
                  </td>
                </tr>
              ) : sortedProducts.length === 0 ? (
                <tr>
                  <td colSpan={5} className="mdf-table__empty">
                    {isServerList
                      ? __(
                          "No products are currently selected for the feed.",
                          "marques-de-france-connector-for-woocommerce",
                        )
                      : __(
                          "No products are currently returned in the feed view.",
                          "marques-de-france-connector-for-woocommerce",
                        )}
                  </td>
                </tr>
              ) : (
                sortedProducts.map((product) => (
                  <tr key={product.id}>
                    <td>
                      <div className="mdf-feed-product-cell">
                        <img
                          src={product.image}
                          alt=""
                          className="mdf-feed-thumb"
                        />
                        <div>
                          <span>{product.name}</span>
                        </div>
                      </div>
                    </td>
                    <td>{product.brand || "—"}</td>
                    <td>{renderPrice(product)}</td>
                    <td>
                      <span
                        style={{
                          background: chipStyle(product.availability)
                            .background,
                          borderRadius: 3,
                          color: chipStyle(product.availability).color,
                          display: "inline-block",
                          fontSize: 11,
                          fontWeight: 600,
                          padding: "2px 8px",
                          textTransform: "capitalize",
                        }}
                      >
                        {product.availability ||
                          __(
                            "Unknown",
                            "marques-de-france-connector-for-woocommerce",
                          )}
                      </span>
                    </td>
                    <td>
                      <span
                        style={{
                          background: chipStyle(
                            product.status || product.feed_status,
                          ).background,
                          borderRadius: 3,
                          color: chipStyle(
                            product.status || product.feed_status,
                          ).color,
                          display: "inline-block",
                          fontSize: 11,
                          fontWeight: 600,
                          padding: "2px 8px",
                          textTransform: "capitalize",
                        }}
                      >
                        {product.status ||
                          product.feed_status ||
                          __(
                            "Active",
                            "marques-de-france-connector-for-woocommerce",
                          )}
                      </span>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
        {feedTotalPages > 1 && (
          <div className="mdf-pagination">
            <span className="mdf-pagination__info">
              {__("Page", "marques-de-france-connector-for-woocommerce")}{" "}
              {feedPage} / {feedTotalPages}
            </span>
            <Button
              variant="secondary"
              style={{ backgroundColor: "#fff" }}
              disabled={feedPage <= 1}
              onClick={() => fetchProducts(feedPage - 1, feedSearch)}
            >
              {__("Previous", "marques-de-france-connector-for-woocommerce")}
            </Button>
            <Button
              variant="secondary"
              style={{ backgroundColor: "#fff" }}
              disabled={feedPage >= feedTotalPages}
              onClick={() => fetchProducts(feedPage + 1, feedSearch)}
            >
              {__("Next", "marques-de-france-connector-for-woocommerce")}
            </Button>
          </div>
        )}
      </>
    );
  }

  if (loading) {
    return (
      <div className="mdf-page">
        <div className="mdf-loading">
          <Spinner />
          {__(
            "Loading feed settings…",
            "marques-de-france-connector-for-woocommerce",
          )}
        </div>
      </div>
    );
  }

  return (
    <div className="mdf-page mdf-feed-page">
      {error && <div className="mdf-error">{error}</div>}

      <div className="mdf-card mdf-feed-card">
        <div className="mdf-chart-controls">
          <strong style={{ fontSize: 14, color: "#051440" }}>
            {__("Feed mode", "marques-de-france-connector-for-woocommerce")}
          </strong>
        </div>
        <p className="mdf-feed-copy">{summaryText}</p>
        <div className="mdf-feed-actions">
          <Button
            variant={isServerList ? "secondary" : "primary"}
            onClick={() => handleModeSwitch("TAG")}
            disabled={switching || !isServerList}
          >
            {__(
              "Use tag-based selection",
              "marques-de-france-connector-for-woocommerce",
            )}
          </Button>
          <Button
            variant={isServerList ? "primary" : "secondary"}
            onClick={() => handleModeSwitch("SERVERLIST")}
            disabled={switching || isServerList}
          >
            {__(
              "Use manual selection",
              "marques-de-france-connector-for-woocommerce",
            )}
          </Button>
        </div>
      </div>

      <div style={{ marginBottom: 16 }}>
        <Notice status="info" isDismissible={false}>
          {__(
            "Out-of-stock, inactive, or products missing a price, image, or brand are automatically excluded from the feed.",
            "marques-de-france-connector-for-woocommerce",
          )}
        </Notice>
      </div>

      <div className="mdf-chart-card">{tableContent}</div>
    </div>
  );
}
