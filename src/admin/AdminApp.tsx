import {
  CheckCircleOutlined,
  CloseCircleOutlined,
  DeleteOutlined,
  DisconnectOutlined,
  EditOutlined,
  KeyOutlined,
  PauseCircleOutlined,
  PlusOutlined,
  SearchOutlined,
  SyncOutlined,
  UnorderedListOutlined,
} from "@ant-design/icons";
import { StyleProvider } from "@ant-design/cssinjs";
import {
  Alert,
  App,
  Button,
  ConfigProvider,
  DatePicker,
  Flex,
  Form,
  Grid,
  Input,
  InputNumber,
  Modal,
  Pagination,
  Select,
  Space,
  Spin,
  Switch,
  Table,
  Tag,
  Tooltip,
  Typography,
  theme,
} from "antd";
import type { ColumnsType } from "antd/es/table";
import dayjs from "dayjs";
import type { CSSProperties } from "react";
import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from "react";

const { Title, Text, Paragraph } = Typography;
const { useBreakpoint } = Grid;
const DEFAULT_PRIMARY_COLOR = "#4f46e5";
const DEFAULT_FONT_FAMILY =
  "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Oxygen-Sans,Ubuntu,Cantarell,'Helvetica Neue',sans-serif";
const THEME_STORAGE_KEY = "wp-react-ui-theme";
const THEME_CHANGE_EVENT = "wp-react-ui-theme-change";
const SHELL_EMBED_MESSAGE_SOURCE = "wp-shell-embed";
const SHELL_EMBED_MESSAGE_VERSION = 1;
const THEME_CSS_VARS = [
  "--font-display",
  "--font-body",
  "--wp-react-ui-font-family",
  "--focus-ring",
  "--color-bg-app",
  "--color-bg-surface",
  "--color-bg-surface-muted",
  "--color-bg-overlay",
  "--color-border-subtle",
  "--color-border-strong",
  "--color-text-primary",
  "--color-text-secondary",
  "--color-text-muted",
  "--color-text-on-accent",
  "--color-accent-primary",
  "--color-accent-primary-hover",
  "--color-accent-soft",
  "--color-success",
  "--color-warning",
  "--color-danger",
  "--color-info",
  "--shell-chrome-bg",
  "--shell-chrome-raised",
  "--surface-inset",
  "--shadow-sm",
  "--shadow-md",
  "--shadow-lg",
] as const;

// ---------------------------------------------------------------------------
// Theme bridge — syncs dark mode + accent color from parent shell iframe
// ---------------------------------------------------------------------------

interface ParentTheme {
  isDark: boolean;
  isHighContrast: boolean;
  primaryColor: string;
  fontFamily: string;
  cssVars: Record<string, string>;
}

function isHtmlElement(value: unknown): value is HTMLElement {
  return (
    !!value &&
    typeof value === "object" &&
    "nodeType" in value &&
    value.nodeType === Node.ELEMENT_NODE &&
    "style" in value
  );
}

function getOverlayContainer(): HTMLElement {
  return document.body;
}

function postShellOverlayState(active: boolean) {
  if (window.parent === window) {
    return;
  }

  window.parent.postMessage(
    {
      source: SHELL_EMBED_MESSAGE_SOURCE,
      version: SHELL_EMBED_MESSAGE_VERSION,
      type: "overlay-state",
      active,
    },
    window.location.origin
  );
}

function getPopupContainer(node?: HTMLElement | null): HTMLElement {
  return node?.ownerDocument?.body ?? document.body;
}

function getParentThemeTargets() {
  try {
    const parentDoc = window.parent?.document;
    if (!parentDoc || parentDoc === document) {
      return null;
    }

    const body = isHtmlElement(parentDoc.body) ? parentDoc.body : null;
    const shellRootElement = parentDoc.getElementById("react-shell-root");
    const shellRoot = isHtmlElement(shellRootElement) ? shellRootElement : null;

    return {
      parentDoc,
      body,
      shellRoot,
      source: shellRoot ?? body ?? parentDoc.documentElement,
    };
  } catch {
    return null;
  }
}

function collectThemeVars(source: HTMLElement): Record<string, string> {
  const styles = getComputedStyle(source);
  return THEME_CSS_VARS.reduce<Record<string, string>>((vars, name) => {
    const value = styles.getPropertyValue(name).trim();
    if (value) {
      vars[name] = value;
    }
    return vars;
  }, {});
}

function readStoredThemePreference(): "light" | "dark" | null {
  try {
    const stored = window.localStorage.getItem(THEME_STORAGE_KEY);
    return stored === "light" || stored === "dark" ? stored : null;
  } catch {
    return null;
  }
}

function readCurrentDocumentTheme(): ParentTheme {
  const body = isHtmlElement(document.body) ? document.body : null;
  const shellRootElement = document.getElementById(APP_ROOT_ID);
  const shellRoot = isHtmlElement(shellRootElement) ? shellRootElement : null;
  const source = shellRoot ?? body ?? document.documentElement;
  const explicitTheme =
    body?.getAttribute("data-theme") ?? document.documentElement.getAttribute("data-theme");
  const storedTheme = readStoredThemePreference();
  const prefersDark =
    typeof window.matchMedia === "function" &&
    window.matchMedia("(prefers-color-scheme: dark)").matches;
  const cssVars = collectThemeVars(source);

  return {
    isDark:
      explicitTheme === "dark" ||
      body?.classList.contains("wp-react-dark") === true ||
      (!explicitTheme && storedTheme === "dark") ||
      (!explicitTheme && storedTheme === null && prefersDark),
    isHighContrast:
      body?.classList.contains("wp-react-ui-high-contrast") === true ||
      document.documentElement.classList.contains("wp-react-ui-high-contrast"),
    primaryColor: cssVars["--color-accent-primary"] ?? DEFAULT_PRIMARY_COLOR,
    fontFamily:
      cssVars["--wp-react-ui-font-family"] ?? cssVars["--font-body"] ?? DEFAULT_FONT_FAMILY,
    cssVars,
  };
}

function readParentTheme(): ParentTheme {
  const targets = getParentThemeTargets();
  if (!targets) {
    return readCurrentDocumentTheme();
  }

  const themeTarget = targets.shellRoot ?? targets.body ?? targets.parentDoc.documentElement;
  const isDark =
    targets.body?.getAttribute("data-theme") === "dark" ||
    targets.shellRoot?.getAttribute("data-theme") === "dark" ||
    themeTarget.getAttribute("data-theme") === "dark" ||
    targets.body?.classList.contains("wp-react-dark") === true;
  const isHighContrast =
    targets.body?.classList.contains("wp-react-ui-high-contrast") === true ||
    targets.shellRoot?.classList.contains("wp-react-ui-high-contrast") === true ||
    themeTarget.classList.contains("wp-react-ui-high-contrast");
  const cssVars = collectThemeVars(targets.source);

  return {
    isDark,
    isHighContrast,
    primaryColor: cssVars["--color-accent-primary"] ?? DEFAULT_PRIMARY_COLOR,
    fontFamily:
      cssVars["--wp-react-ui-font-family"] ??
      cssVars["--font-body"] ??
      DEFAULT_FONT_FAMILY,
    cssVars,
  };
}

function applyThemeToIframe(themeState: ParentTheme) {
  const body = document.body;
  const appContainerElement = document.getElementById(APP_CONTAINER_ID);
  const appContainer = isHtmlElement(appContainerElement) ? appContainerElement : null;
  const shellRootElement = document.getElementById(APP_ROOT_ID);
  const shellRoot = isHtmlElement(shellRootElement) ? shellRootElement : null;
  const targets = [document.documentElement, body, appContainer, shellRoot].filter(
    (target): target is HTMLElement => isHtmlElement(target)
  );

  for (const target of targets) {
    for (const [name, value] of Object.entries(themeState.cssVars)) {
      target.style.setProperty(name, value);
    }

    target.setAttribute("data-theme", themeState.isDark ? "dark" : "light");
    target.classList.toggle("wp-react-ui-high-contrast", themeState.isHighContrast);
  }

  if (body) {
    body.classList.toggle("wp-react-dark", themeState.isDark);
  }
}

function useParentTheme(): ParentTheme {
  const [state, setState] = useState<ParentTheme>(readParentTheme);
  const observerRef = useRef<MutationObserver | null>(null);

  useLayoutEffect(() => {
    const scheduleRefresh = () => {
      window.requestAnimationFrame(() => {
        const next = readParentTheme();
        applyThemeToIframe(next);
        setState(next);
      });
    };

    const refresh = () => {
      const next = readParentTheme();
      applyThemeToIframe(next);
      setState(next);
    };

    const handleThemeEvent = () => scheduleRefresh();
    const handleMessage = (event: MessageEvent) => {
      const data = event.data;
      if (
        data &&
        typeof data === "object" &&
        "type" in data &&
        data.type === THEME_CHANGE_EVENT
      ) {
        scheduleRefresh();
      }
    };

    refresh();

    window.addEventListener("storage", refresh);
    window.addEventListener(THEME_CHANGE_EVENT, handleThemeEvent as EventListener);
    window.addEventListener("message", handleMessage);

    const mediaQuery =
      typeof window.matchMedia === "function"
        ? window.matchMedia("(prefers-color-scheme: dark)")
        : null;
    const handleMediaChange = () => refresh();
    mediaQuery?.addEventListener?.("change", handleMediaChange);

    const targets = getParentThemeTargets();
    if (!targets) {
      return () => {
        window.removeEventListener("storage", refresh);
        window.removeEventListener(THEME_CHANGE_EVENT, handleThemeEvent as EventListener);
        window.removeEventListener("message", handleMessage);
        mediaQuery?.removeEventListener?.("change", handleMediaChange);
      };
    }

    try {
      observerRef.current = new MutationObserver(refresh);

      for (const target of [
        targets.parentDoc.documentElement,
        targets.body,
        targets.shellRoot,
      ]) {
        if (isHtmlElement(target)) {
          observerRef.current.observe(target, {
            attributes: true,
            attributeFilter: ["class", "data-theme", "style"],
          });
        }
      }

      targets.parentDoc.defaultView?.addEventListener(
        THEME_CHANGE_EVENT,
        handleThemeEvent as EventListener
      );
    } catch {
      // Cross-origin or parent window access unavailable.
    }

    return () => {
      observerRef.current?.disconnect();
      window.removeEventListener("storage", refresh);
      window.removeEventListener(THEME_CHANGE_EVENT, handleThemeEvent as EventListener);
      window.removeEventListener("message", handleMessage);
      mediaQuery?.removeEventListener?.("change", handleMediaChange);
      try {
        targets.parentDoc.defaultView?.removeEventListener(
          THEME_CHANGE_EVENT,
          handleThemeEvent as EventListener
        );
      } catch {
        // No-op.
      }
    };
  }, []);

  return state;
}

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

interface Tier {
  value: string;
  label: string;
  maxActivations: number;
  features: string[];
}

interface License {
  id: number;
  keyPrefix: string;
  customerName: string;
  customerEmail: string;
  role: "owner" | "customer";
  tier: string;
  status: string;
  maxActivations: number;
  currentActivations: number;
  paymentInterval: string;
  autoRenewal: boolean;
  notes: string | null;
  createdAt: string;
  validUntil: string;
}

interface AdminConfig {
  restBase: string;
  nonce: string;
  tiers: Tier[];
  pageTitle: string;
  status: string;
}

// ---------------------------------------------------------------------------
// Global config
// ---------------------------------------------------------------------------

const config: AdminConfig = (window as unknown as { WpLicenseServerAdmin: AdminConfig })
  .WpLicenseServerAdmin;

// ---------------------------------------------------------------------------
// API helpers
// ---------------------------------------------------------------------------

async function apiFetch<T>(path: string, options: RequestInit = {}): Promise<T> {
  const res = await fetch(`${config.restBase}${path}`, {
    headers: {
      "Content-Type": "application/json",
      "X-WP-Nonce": config.nonce,
      ...((options.headers as Record<string, string>) ?? {}),
    },
    ...options,
  });

  const body = (await res.json()) as { message?: string } & T;

  if (!res.ok) {
    const msg = (body as { message?: string }).message ?? `Request failed (${res.status})`;
    throw new Error(msg);
  }

  return body;
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function statusColor(status: string): string {
  switch (status) {
    case "active":
      return "success";
    case "expired":
      return "warning";
    case "suspended":
      return "processing";
    case "cancelled":
      return "error";
    default:
      return "default";
  }
}

function statusIcon(status: string) {
  switch (status) {
    case "active":
      return <CheckCircleOutlined />;
    case "expired":
      return <CloseCircleOutlined />;
    case "suspended":
      return <PauseCircleOutlined />;
    case "cancelled":
      return <CloseCircleOutlined />;
    default:
      return <SyncOutlined />;
  }
}

function formatDate(iso: string): string {
  return iso ? new Date(iso).toLocaleDateString() : "—";
}

function showSuccessNotification(
  notification: ReturnType<typeof App.useApp>["notification"],
  config: { message: string; description?: string; duration?: number }
) {
  notification.success({
    placement: "bottomRight",
    duration: config.duration ?? 4.5,
    style: { zIndex: 100300 },
    ...config,
  });
}

function showErrorNotification(
  notification: ReturnType<typeof App.useApp>["notification"],
  config: { message: string; description?: string; duration?: number }
) {
  notification.error({
    placement: "bottomRight",
    duration: config.duration ?? 5,
    style: { zIndex: 100300 },
    ...config,
  });
}

// ---------------------------------------------------------------------------
// SurfacePanel — mirrors the shell's shared/ui/SurfacePanel structure
// ---------------------------------------------------------------------------

interface SurfacePanelProps {
  title: React.ReactNode;
  description?: React.ReactNode;
  icon?: React.ReactNode;
  extra?: React.ReactNode;
  children: React.ReactNode;
  className?: string;
  bodyClassName?: string;
}

function SurfacePanel({
  title,
  description,
  icon,
  extra,
  children,
  className,
  bodyClassName,
}: SurfacePanelProps) {
  return (
    <section className={["wp-react-ui-surface-panel", className].filter(Boolean).join(" ")}>
      <div className="wp-react-ui-surface-panel__header">
        <div className="wp-react-ui-surface-panel__lead">
          {icon ? <span className="wp-react-ui-surface-panel__icon">{icon}</span> : null}
          <div className="wp-react-ui-surface-panel__copy">
            <div className="wp-react-ui-surface-panel__title">
              <Title level={5} style={{ margin: 0, fontSize: 15, fontWeight: 600 }}>
                {title}
              </Title>
            </div>
            {description ? (
              <div className="wp-react-ui-surface-panel__description">
                <Text type="secondary" style={{ fontSize: 13 }}>
                  {description}
                </Text>
              </div>
            ) : null}
          </div>
        </div>
        {extra ? <div className="wp-react-ui-surface-panel__extra">{extra}</div> : null}
      </div>
      <div
        className={["wp-react-ui-surface-panel__body", bodyClassName].filter(Boolean).join(" ")}
      >
        {children}
      </div>
    </section>
  );
}

interface MetricTileProps {
  label: string;
  value: string | number;
  meta: string;
  icon: React.ReactNode;
  accent?: "primary" | "success" | "default";
}

function MetricTile({ label, value, meta, icon, accent = "default" }: MetricTileProps) {
  const { token } = theme.useToken();
  const accentColor =
    accent === "primary"
      ? token.colorPrimary
      : accent === "success"
        ? token.colorSuccess
        : token.colorTextSecondary;
  const style = { ["--metric-accent" as string]: accentColor } as CSSProperties;

  return (
    <div className="wp-react-ui-metric-tile" style={style}>
      <div className="wp-react-ui-metric-tile__header">
        <Text className="wp-react-ui-metric-tile__label">{label}</Text>
        <span className="wp-react-ui-metric-tile__icon">{icon}</span>
      </div>
      <div className="wp-react-ui-metric-tile__body">
        <div className="wp-react-ui-metric-tile__value">{value}</div>
      </div>
      <div className="wp-react-ui-metric-tile__footer">
        <Text type="secondary" style={{ fontSize: 13 }}>
          {meta}
        </Text>
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Create License Form
// ---------------------------------------------------------------------------

interface CreateFormValues {
  customerEmail: string;
  customerName?: string;
  role: "owner" | "customer";
  tier: string;
  validUntil: dayjs.Dayjs;
  paymentInterval: string;
  notes?: string;
}

interface EditFormValues extends CreateFormValues {
  status: "active" | "expired" | "suspended" | "cancelled";
  autoRenewal: boolean;
  maxActivations: number;
}

const APP_CONTAINER_ID = "wp-license-server-admin-root";
const APP_ROOT_ID = "wp-license-server-admin-react-root";
const PAYMENT_INTERVAL_OPTIONS = [
  { value: "monthly", label: "Monthly" },
  { value: "yearly", label: "Yearly" },
];

interface CreateLicenseModalProps {
  open: boolean;
  tiers: Tier[];
  ownerLicenseId: number | null;
  onCancel: () => void;
  onCreated: (licenseKey: string) => void;
}

function CreateLicenseModal({
  open,
  tiers,
  ownerLicenseId,
  onCancel,
  onCreated,
}: CreateLicenseModalProps) {
  const { notification } = App.useApp();
  const [form] = Form.useForm<CreateFormValues>();
  const [creating, setCreating] = useState(false);
  const ownerOptionDisabled = ownerLicenseId !== null;

  useEffect(() => {
    if (!open) {
      form.resetFields();
    }
  }, [form, open]);

  const handleSubmit = useCallback(
    async (values: CreateFormValues) => {
      setCreating(true);
      try {
        const data = await apiFetch<{ licenseKey: string }>("/licenses", {
          method: "POST",
          body: JSON.stringify({
            customerEmail: values.customerEmail,
            customerName: values.customerName ?? "",
            role: values.role,
            tier: values.tier,
            validUntil: values.validUntil.format("YYYY-MM-DD"),
            paymentInterval: values.paymentInterval,
            notes: values.notes ?? "",
          }),
        });

        onCreated(data.licenseKey);
        form.resetFields();

        showSuccessNotification(notification, {
          message: "License created",
          description: "The full key is shown above. Copy it now — it won't be shown again.",
          duration: 6,
        });
      } catch (err) {
        showErrorNotification(notification, {
          message: "Could not create license",
          description: err instanceof Error ? err.message : "Unknown error",
        });
      } finally {
        setCreating(false);
      }
    },
    [form, notification, onCreated]
  );

  if (!open) {
    return null;
  }

  return (
    <Modal
      destroyOnClose
      open={open}
      title="Create license"
      okText="Create license"
      onCancel={onCancel}
      onOk={() => form.submit()}
      confirmLoading={creating}
      width={680}
      getContainer={false}
      zIndex={100200}
    >
      <Form
        form={form}
        layout="vertical"
        onFinish={handleSubmit}
        initialValues={{
          role: "customer",
          tier: tiers[0]?.value ?? "pro",
          paymentInterval: "yearly",
        }}
      >
        <div className="wp-license-server-admin-form-grid">
          <Form.Item
            label="Customer Email"
            name="customerEmail"
            rules={[
              { required: true, message: "Email is required" },
              { type: "email", message: "Enter a valid email" },
            ]}
          >
            <Input placeholder="customer@example.com" />
          </Form.Item>

          <Form.Item label="Customer Name" name="customerName">
            <Input placeholder="Jane Smith" />
          </Form.Item>

          <Form.Item label="Role" name="role" rules={[{ required: true }]}>
            <Select
              options={[
                { value: "customer", label: "Customer" },
                {
                  value: "owner",
                  label: ownerOptionDisabled ? "Owner (already assigned)" : "Owner",
                  disabled: ownerOptionDisabled,
                },
              ]}
            />
          </Form.Item>

          <Form.Item label="License Tier" name="tier" rules={[{ required: true }]}>
            <Select>
              {tiers.map((t) => (
                <Select.Option key={t.value} value={t.value}>
                  {t.label}{" "}
                  <Text type="secondary" style={{ fontSize: 12 }}>
                    ({t.maxActivations} activations)
                  </Text>
                </Select.Option>
              ))}
            </Select>
          </Form.Item>

          <Form.Item
            label="Valid Until"
            name="validUntil"
            rules={[
              { required: true, message: "Expiry date is required" },
              {
                validator: (_, value: dayjs.Dayjs) =>
                  value && value.endOf("day").isAfter(dayjs())
                    ? Promise.resolve()
                    : Promise.reject(new Error("Date must be in the future")),
              },
            ]}
          >
            <DatePicker
              style={{ width: "100%" }}
              disabledDate={(d) => d.isBefore(dayjs(), "day")}
              format="YYYY-MM-DD"
            />
          </Form.Item>

          <Form.Item
            label="Payment Interval"
            name="paymentInterval"
            rules={[{ required: true }]}
          >
            <Select options={PAYMENT_INTERVAL_OPTIONS} />
          </Form.Item>
        </div>

        <Form.Item label="Notes" name="notes" style={{ marginBottom: 0 }}>
          <Input.TextArea rows={3} placeholder="Optional notes…" />
        </Form.Item>
      </Form>
    </Modal>
  );
}

interface EditLicenseModalProps {
  open: boolean;
  license: License | null;
  tiers: Tier[];
  ownerLicenseId: number | null;
  onCancel: () => void;
  onSaved: (license: License) => void;
}

function EditLicenseModal({
  open,
  license,
  tiers,
  ownerLicenseId,
  onCancel,
  onSaved,
}: EditLicenseModalProps) {
  const { notification } = App.useApp();
  const [form] = Form.useForm<EditFormValues>();
  const [saving, setSaving] = useState(false);
  const statusValue = Form.useWatch("status", form);
  const ownerOptionDisabled = ownerLicenseId !== null && ownerLicenseId !== license?.id;

  useEffect(() => {
    if (!open || !license) {
      form.resetFields();
      return;
    }

    form.setFieldsValue({
      customerEmail: license.customerEmail,
      customerName: license.customerName,
      role: license.role,
      tier: license.tier,
      status: license.status as EditFormValues["status"],
      validUntil: dayjs(license.validUntil),
      paymentInterval: license.paymentInterval,
      autoRenewal: license.autoRenewal,
      maxActivations: license.maxActivations,
      notes: license.notes ?? "",
    });
  }, [form, license, open]);

  const handleSubmit = useCallback(async () => {
    if (!license) {
      return;
    }

    try {
      const values = await form.validateFields();
      const originalDate = dayjs(license.validUntil).format("YYYY-MM-DD");
      const selectedDate = values.validUntil.format("YYYY-MM-DD");
      setSaving(true);

      const data = await apiFetch<{ item: License }>(`/licenses/${license.id}`, {
        method: "PUT",
        body: JSON.stringify({
          customerEmail: values.customerEmail,
          customerName: values.customerName ?? "",
          role: values.role,
          tier: values.tier,
          status: values.status,
          validUntil: selectedDate === originalDate ? license.validUntil : selectedDate,
          paymentInterval: values.paymentInterval,
          autoRenewal: values.autoRenewal,
          maxActivations: values.maxActivations,
          notes: values.notes ?? "",
        }),
      });

      showSuccessNotification(notification, {
        message: "License updated",
        description: "The license entry was saved successfully.",
      });
      onSaved(data.item);
    } catch (err) {
      if (err && typeof err === "object" && "errorFields" in err) {
        return;
      }

      showErrorNotification(notification, {
        message: "Could not update license",
        description: err instanceof Error ? err.message : "Unknown error",
      });
    } finally {
      setSaving(false);
    }
  }, [form, license, notification, onSaved]);

  if (!open || !license) {
    return null;
  }

  return (
    <Modal
      destroyOnClose
      open={open}
      title={license ? `Edit ${license.keyPrefix}…` : "Edit license"}
      okText="Save changes"
      onCancel={onCancel}
      onOk={() => void handleSubmit()}
      confirmLoading={saving}
      width={680}
      getContainer={false}
      zIndex={100200}
    >
      <Form form={form} layout="vertical">
        <div className="wp-license-server-admin-form-grid">
          <Form.Item
            label="Customer Email"
            name="customerEmail"
            rules={[
              { required: true, message: "Email is required" },
              { type: "email", message: "Enter a valid email" },
            ]}
          >
            <Input placeholder="customer@example.com" />
          </Form.Item>

          <Form.Item label="Customer Name" name="customerName">
            <Input placeholder="Jane Smith" />
          </Form.Item>

          <Form.Item label="Role" name="role" rules={[{ required: true }]}>
            <Select
              options={[
                { value: "customer", label: "Customer" },
                {
                  value: "owner",
                  label: ownerOptionDisabled ? "Owner (already assigned)" : "Owner",
                  disabled: ownerOptionDisabled,
                },
              ]}
            />
          </Form.Item>

          <Form.Item label="Status" name="status" rules={[{ required: true }]}>
            <Select
              options={[
                { value: "active", label: "Active" },
                { value: "expired", label: "Expired" },
                { value: "suspended", label: "Suspended" },
                { value: "cancelled", label: "Cancelled" },
              ]}
            />
          </Form.Item>

          <Form.Item label="License Tier" name="tier" rules={[{ required: true }]}>
            <Select
              onChange={(value: string) => {
                const tier = tiers.find((item) => item.value === value);
                if (tier) {
                  form.setFieldValue("maxActivations", tier.maxActivations);
                }
              }}
            >
              {tiers.map((tier) => (
                <Select.Option key={tier.value} value={tier.value}>
                  {tier.label}
                </Select.Option>
              ))}
            </Select>
          </Form.Item>

          <Form.Item
            label="Max Activations"
            name="maxActivations"
            tooltip="Override the tier default when this license needs a custom limit."
            rules={[{ required: true, message: "Activation limit is required" }]}
          >
            <InputNumber min={1} style={{ width: "100%" }} />
          </Form.Item>

          <Form.Item
            label="Valid Until"
            name="validUntil"
            rules={[
              { required: true, message: "Expiry date is required" },
              {
                validator: (_, value: dayjs.Dayjs | undefined) =>
                  statusValue !== "active" || (value && value.endOf("day").isAfter(dayjs()))
                    ? Promise.resolve()
                    : Promise.reject(
                        new Error("Active licenses must use a future expiry date")
                      ),
              },
            ]}
          >
            <DatePicker
              style={{ width: "100%" }}
              format="YYYY-MM-DD"
              disabledDate={(date) =>
                statusValue === "active" ? date.isBefore(dayjs(), "day") : false
              }
            />
          </Form.Item>

          <Form.Item
            label="Payment Interval"
            name="paymentInterval"
            rules={[{ required: true }]}
          >
            <Select options={PAYMENT_INTERVAL_OPTIONS} />
          </Form.Item>

          <Form.Item
            label="Auto Renewal"
            name="autoRenewal"
            valuePropName="checked"
            tooltip="Disable this for manually managed or one-off licenses."
          >
            <Switch />
          </Form.Item>
        </div>

        <Form.Item label="Notes" name="notes">
          <Input.TextArea rows={4} placeholder="Internal notes for this license…" />
        </Form.Item>
      </Form>
    </Modal>
  );
}

// ---------------------------------------------------------------------------
// Licenses Table
// ---------------------------------------------------------------------------

const STATUS_FILTERS = [
  { label: "All", value: "" },
  { label: "Active", value: "active" },
  { label: "Expired", value: "expired" },
  { label: "Suspended", value: "suspended" },
  { label: "Cancelled", value: "cancelled" },
];

interface LicenseTableProps {
  licenses: License[];
  tiers: Tier[];
  loading: boolean;
  statusFilter: string;
  onStatusFilterChange: (v: string) => void;
  onRefresh: () => void;
  onEdit: (license: License) => void;
  onDelete: (id: number) => void;
  onDeactivateAll: (id: number) => void;
}

function LicensesTable({
  licenses,
  tiers,
  loading,
  statusFilter,
  onStatusFilterChange,
  onRefresh: _onRefresh,
  onEdit,
  onDelete,
  onDeactivateAll,
}: LicenseTableProps) {
  const tierMap = Object.fromEntries(tiers.map((t) => [t.value, t.label]));
  const [searchQuery, setSearchQuery] = useState("");
  const [pageSize, setPageSize] = useState(30);
  const [currentPage, setCurrentPage] = useState(1);

  const filteredLicenses = useMemo(() => {
    const query = searchQuery.trim().toLowerCase();
    if (!query) {
      return licenses;
    }

    return licenses.filter((license) =>
      [
        license.keyPrefix,
        license.customerName,
        license.customerEmail,
        license.role,
        license.tier,
        license.status,
        license.paymentInterval,
        tierMap[license.tier] ?? "",
      ]
        .join(" ")
        .toLowerCase()
        .includes(query)
    );
  }, [licenses, searchQuery, tierMap]);

  const paginatedLicenses = useMemo(() => {
    const start = (currentPage - 1) * pageSize;
    return filteredLicenses.slice(start, start + pageSize);
  }, [currentPage, filteredLicenses, pageSize]);

  useEffect(() => {
    setCurrentPage(1);
  }, [statusFilter, searchQuery, pageSize]);

  useEffect(() => {
    const maxPage = Math.max(1, Math.ceil(filteredLicenses.length / pageSize));
    if (currentPage > maxPage) {
      setCurrentPage(maxPage);
    }
  }, [currentPage, filteredLicenses.length, pageSize]);

  const columns: ColumnsType<License> = [
    {
      title: "Key Prefix",
      dataIndex: "keyPrefix",
      key: "keyPrefix",
      render: (v: string) => (
        <Text code style={{ fontSize: 12 }}>
          {v}…
        </Text>
      ),
    },
    {
      title: "Customer",
      key: "customer",
      render: (_: unknown, r: License) => (
        <Space direction="vertical" size={0}>
          <Text strong style={{ fontSize: 13 }}>
            {r.customerName || "—"}
          </Text>
          <Text type="secondary" style={{ fontSize: 12 }}>
            {r.customerEmail}
          </Text>
        </Space>
      ),
    },
    {
      title: "Tier",
      dataIndex: "tier",
      key: "tier",
      render: (v: string) => tierMap[v] ?? v,
    },
    {
      title: "Role",
      dataIndex: "role",
      key: "role",
      render: (v: License["role"]) => (
        <Tag color={v === "owner" ? "purple" : "blue"}>
          {v === "owner" ? "Owner" : "Customer"}
        </Tag>
      ),
    },
    {
      title: "Status",
      dataIndex: "status",
      key: "status",
      render: (v: string) => (
        <Tag color={statusColor(v)} icon={statusIcon(v)}>
          {v.charAt(0).toUpperCase() + v.slice(1)}
        </Tag>
      ),
    },
    {
      title: "Activations",
      key: "activations",
      render: (_: unknown, r: License) => (
        <Text>
          {r.currentActivations} / {r.maxActivations}
        </Text>
      ),
    },
    {
      title: "Valid Until",
      dataIndex: "validUntil",
      key: "validUntil",
      render: (v: string) => formatDate(v),
    },
    {
      title: "Actions",
      key: "actions",
      align: "right",
      render: (_: unknown, r: License) => (
        <Space>
          <Tooltip title="Edit license">
            <Button size="small" icon={<EditOutlined />} onClick={() => onEdit(r)}>
              Edit
            </Button>
          </Tooltip>
          {r.currentActivations > 0 && (
            <Tooltip title="Deactivate all domains">
              <Button
                size="small"
                icon={<DisconnectOutlined />}
                onClick={() => onDeactivateAll(r.id)}
              >
                Deactivate All
              </Button>
            </Tooltip>
          )}
          <Tooltip title="Delete license">
            <Button size="small" danger icon={<DeleteOutlined />} onClick={() => onDelete(r.id)} />
          </Tooltip>
        </Space>
      ),
    },
  ];

  return (
    <div className="wp-license-server-admin-license-layout">
      <div className="wp-license-server-admin-toolbar">
        <div className="wp-license-server-admin-toolbar__filters">
          <Input
            allowClear
            value={searchQuery}
            onChange={(event) => setSearchQuery(event.target.value)}
            placeholder="Search licenses"
            prefix={<SearchOutlined />}
            size="large"
            className="wp-license-server-admin-toolbar__search"
          />
          <Select
            value={pageSize}
            onChange={(value) => setPageSize(value)}
            size="large"
            style={{ width: 128 }}
            options={[
              { value: 30, label: "30 / page" },
              { value: 20, label: "20 / page" },
              { value: 10, label: "10 / page" },
              { value: 5, label: "5 / page" },
            ]}
          />
          <Select
            value={statusFilter}
            onChange={onStatusFilterChange}
            size="large"
            style={{ width: 140 }}
            options={STATUS_FILTERS.map((s) => ({ value: s.value, label: s.label }))}
          />
        </div>
      </div>
      {loading && licenses.length === 0 ? (
        <Flex justify="center" style={{ padding: "40px 0" }}>
          <Spin size="large" />
        </Flex>
      ) : (
        <div className="wp-license-server-admin-table-shell">
          <Table<License>
            rowKey="id"
            columns={columns}
            dataSource={paginatedLicenses}
            loading={loading}
            size="small"
            pagination={false}
            scroll={{ x: "max-content" }}
            locale={{ emptyText: "No licenses found." }}
            expandable={{
              expandedRowRender: (r) =>
                r.notes ? (
                  <Text type="secondary" style={{ fontSize: 12 }}>
                    {r.notes}
                  </Text>
                ) : null,
              rowExpandable: (r) => !!r.notes,
            }}
          />
        </div>
      )}
      {filteredLicenses.length > pageSize && (
        <Flex justify="flex-end" style={{ marginTop: 16 }}>
          <Pagination
            current={currentPage}
            pageSize={pageSize}
            total={filteredLicenses.length}
            showSizeChanger={false}
            onChange={(page) => setCurrentPage(page)}
          />
        </Flex>
      )}
    </div>
  );
}

// ---------------------------------------------------------------------------
// Main App
// ---------------------------------------------------------------------------

function AdminAppInner() {
  const { modal, notification } = App.useApp();
  const screens = useBreakpoint();

  const [licenses, setLicenses] = useState<License[]>([]);
  const [tiers, setTiers] = useState<Tier[]>(config.tiers ?? []);
  const [ownerLicenseId, setOwnerLicenseId] = useState<number | null>(null);
  const [loading, setLoading] = useState(true);
  const [statusFilter, setStatusFilter] = useState(config.status ?? "");
  const [newLicenseKey, setNewLicenseKey] = useState<string | null>(null);
  const [createModalOpen, setCreateModalOpen] = useState(false);
  const [editingLicense, setEditingLicense] = useState<License | null>(null);
  const [confirmOverlayCount, setConfirmOverlayCount] = useState(0);
  const overlayActive = createModalOpen || editingLicense !== null || confirmOverlayCount > 0;

  const fetchLicenses = useCallback(async () => {
    setLoading(true);
    try {
      const data = await apiFetch<{ items: License[]; tiers: Tier[]; ownerLicenseId?: number | null }>(
        `/licenses${statusFilter ? `?status=${encodeURIComponent(statusFilter)}` : ""}`
      );
      setLicenses(data.items);
      if (data.tiers) setTiers(data.tiers);
      setOwnerLicenseId(typeof data.ownerLicenseId === "number" ? data.ownerLicenseId : null);
    } catch (err) {
      showErrorNotification(notification, {
        message: "Failed to load licenses",
        description: err instanceof Error ? err.message : "Unknown error",
      });
    } finally {
      setLoading(false);
    }
  }, [statusFilter, notification]);

  useEffect(() => {
    void fetchLicenses();
  }, [fetchLicenses]);

  useEffect(() => {
    postShellOverlayState(overlayActive);
  }, [overlayActive]);

  useEffect(
    () => () => {
      postShellOverlayState(false);
    },
    []
  );

  const markConfirmOverlayClosed = useCallback(() => {
    setConfirmOverlayCount((count) => Math.max(0, count - 1));
  }, []);

  const handleDelete = useCallback(
    (id: number) => {
      setConfirmOverlayCount((count) => count + 1);
      modal.confirm({
        title: "Delete license?",
        content: "This action cannot be undone.",
        okText: "Delete",
        okButtonProps: { danger: true },
        getContainer: getOverlayContainer,
        afterClose: markConfirmOverlayClosed,
        onOk: async () => {
          try {
            await apiFetch(`/licenses/${id}`, { method: "DELETE" });
            showSuccessNotification(notification, { message: "License deleted" });
            void fetchLicenses();
          } catch (err) {
            showErrorNotification(notification, {
              message: "Could not delete license",
              description: err instanceof Error ? err.message : "Unknown error",
            });
            throw err;
          }
        },
      });
    },
    [modal, notification, fetchLicenses, markConfirmOverlayClosed]
  );

  const handleDeactivateAll = useCallback(
    (id: number) => {
      setConfirmOverlayCount((count) => count + 1);
      modal.confirm({
        title: "Deactivate all domains for this license?",
        okText: "Deactivate All",
        getContainer: getOverlayContainer,
        afterClose: markConfirmOverlayClosed,
        onOk: async () => {
          try {
            const data = await apiFetch<{ deactivated: number }>(
              `/licenses/${id}/deactivate-all`,
              { method: "POST" }
            );
            showSuccessNotification(notification, {
              message: `${data.deactivated} activation(s) removed`,
            });
            void fetchLicenses();
          } catch (err) {
            showErrorNotification(notification, {
              message: "Could not deactivate activations",
              description: err instanceof Error ? err.message : "Unknown error",
            });
            throw err;
          }
        },
      });
    },
    [modal, notification, fetchLicenses, markConfirmOverlayClosed]
  );

  const handleLicenseCreated = useCallback(
    (key: string) => {
      setCreateModalOpen(false);
      setNewLicenseKey(key);
      void fetchLicenses();
    },
    [fetchLicenses]
  );

  const handleLicenseSaved = useCallback(
    (license: License) => {
      setEditingLicense(null);
      setLicenses((current) =>
        current.map((item) => (item.id === license.id ? license : item))
      );
      void fetchLicenses();
    },
    [fetchLicenses]
  );

  const activeLicenses = licenses.filter((license) => license.status === "active").length;
  const ownerLicenses = ownerLicenseId === null ? 0 : 1;
  const currentActivations = licenses.reduce(
    (total, license) => total + license.currentActivations,
    0
  );

  return (
    <main className="wp-react-ui-page-canvas wp-license-server-admin-shell">
      <div className="wp-react-ui-page-canvas__inner">
        <div className="wp-react-ui-page-intro">
          <Flex
            className="wp-react-ui-page-intro__header"
            justify="space-between"
            align="flex-start"
            gap={24}
            wrap
          >
            <div className="wp-react-ui-page-intro__copy" style={{ minWidth: 0 }}>
              <div className="wp-license-server-admin-eyebrow">Central license operations</div>
              <Title
                level={2}
                className="wp-react-ui-page-intro__title"
                style={{ marginBottom: 6, fontSize: screens.md ? 30 : 24 }}
              >
                License Manager
              </Title>
              <Paragraph
                type="secondary"
                className="wp-react-ui-page-intro__description"
                style={{ marginBottom: 0, maxWidth: 760, fontSize: 14 }}
              >
                Issue, monitor, and revoke licenses for all plugin customers.123123
              </Paragraph>

            </div>

            <Flex className="wp-react-ui-page-intro__actions" gap={12} wrap align="center">
              <Button type="primary" icon={<PlusOutlined />} onClick={() => setCreateModalOpen(true)}>
                Create license
              </Button>
            </Flex>
          </Flex>
        </div>

        {newLicenseKey && (
          <Alert
            type="success"
            showIcon
            closable
            onClose={() => setNewLicenseKey(null)}
            style={{ marginBottom: 24 }}
            message="License created — copy the full key now"
            description={
              <Space>
                <Text strong>Full key:</Text>
                <Text code copyable={{ text: newLicenseKey }} style={{ fontSize: 13 }}>
                  {newLicenseKey}
                </Text>
              </Space>
            }
          />
        )}

        <div className="wp-license-server-admin-metric-grid">
          <MetricTile
            label="Total licenses"
            value={licenses.length}
            meta="All keys currently stored on this server."
            icon={<KeyOutlined />}
            accent="primary"
          />
          <MetricTile
            label="Active now"
            value={activeLicenses}
            meta="Licenses that can currently activate clients."
            icon={<CheckCircleOutlined />}
            accent="success"
          />
          <MetricTile
            label="Owner keys"
            value={ownerLicenses}
            meta="Owner licenses can view the full support inbox."
            icon={<UnorderedListOutlined />}
          />
          <MetricTile
            label="Client activations"
            value={currentActivations}
            meta="Combined active site installations across all keys."
            icon={<DisconnectOutlined />}
          />
        </div>

        <SurfacePanel
          className="wp-license-server-admin-overview-panel"
          icon={<UnorderedListOutlined />}
          title={<Title level={4} style={{ margin: 0, fontSize: 20 }}>License Overview</Title>}
          description="Monitor every issued key, edit customer metadata, and manage activations without leaving the server console."
        >
          <LicensesTable
            licenses={licenses}
            tiers={tiers}
            loading={loading}
            statusFilter={statusFilter}
            onStatusFilterChange={setStatusFilter}
            onRefresh={() => void fetchLicenses()}
            onEdit={setEditingLicense}
            onDelete={handleDelete}
            onDeactivateAll={handleDeactivateAll}
          />
        </SurfacePanel>

        <CreateLicenseModal
          open={createModalOpen}
          tiers={tiers}
          ownerLicenseId={ownerLicenseId}
          onCancel={() => setCreateModalOpen(false)}
          onCreated={handleLicenseCreated}
        />
        <EditLicenseModal
          open={editingLicense !== null}
          license={editingLicense}
          tiers={tiers}
          ownerLicenseId={ownerLicenseId}
          onCancel={() => setEditingLicense(null)}
          onSaved={handleLicenseSaved}
        />
      </div>
    </main>
  );
}

// ---------------------------------------------------------------------------
// Root with providers
// ---------------------------------------------------------------------------

export default function AdminApp() {
  const parentTheme = useParentTheme();
  const isDark = parentTheme.isDark;
  const darkTokenOverrides = useMemo(
    () =>
      isDark
        ? {
            colorBgContainer: parentTheme.cssVars["--color-bg-surface"] ?? "#131c2b",
            colorBgElevated: parentTheme.cssVars["--color-bg-surface-muted"] ?? "#1a2435",
            colorBgLayout: parentTheme.cssVars["--color-bg-app"] ?? "#0f1723",
            colorFillAlter:
              parentTheme.cssVars["--surface-inset"] ??
              parentTheme.cssVars["--color-bg-surface-muted"] ??
              "#1a2435",
            colorFillSecondary:
              parentTheme.cssVars["--shell-chrome-bg"] ??
              parentTheme.cssVars["--color-bg-surface-muted"] ??
              "#1e2a3b",
            colorBorderSecondary:
              parentTheme.cssVars["--color-border-subtle"] ?? "rgba(255,255,255,0.09)",
            colorBorder:
              parentTheme.cssVars["--color-border-strong"] ?? "rgba(255,255,255,0.12)",
            colorText: parentTheme.cssVars["--color-text-primary"] ?? "#f8fafc",
            colorTextSecondary: parentTheme.cssVars["--color-text-secondary"] ?? "#cbd5e1",
            colorTextPlaceholder: parentTheme.cssVars["--color-text-muted"] ?? "#94a3b8",
          }
        : {},
    [isDark, parentTheme.cssVars]
  );

  return (
    <StyleProvider hashPriority="high">
      <ConfigProvider
        getPopupContainer={(node) => getPopupContainer(node)}
        theme={{
          algorithm: isDark ? theme.darkAlgorithm : theme.defaultAlgorithm,
          token: {
            colorPrimary: parentTheme.primaryColor,
            borderRadius: 6,
            fontFamily: parentTheme.fontFamily,
            fontSize: 13,
            zIndexPopupBase: 100260,
            ...darkTokenOverrides,
          },
          components: {
            Table: { fontSize: 13 },
            Form: { labelFontSize: 13 },
            ...(isDark && {
              Button: {
                defaultBg: "rgba(255,255,255,0.06)",
                defaultBorderColor: "rgba(255,255,255,0.18)",
                defaultColor: "#e2e8f0",
                defaultHoverBg: "rgba(255,255,255,0.10)",
                defaultHoverBorderColor: "rgba(255,255,255,0.28)",
                defaultHoverColor: "#f8fafc",
                defaultActiveBg: "rgba(255,255,255,0.13)",
                defaultActiveBorderColor: "rgba(255,255,255,0.32)",
              },
            }),
          },
        }}
      >
        <App
          notification={{
            placement: "bottomRight",
            duration: 4.5,
            getContainer: getOverlayContainer,
            maxCount: 3,
          }}
        >
          <AdminAppInner />
        </App>
      </ConfigProvider>
    </StyleProvider>
  );
}
