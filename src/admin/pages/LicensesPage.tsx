import {
  DeleteOutlined,
  DisconnectOutlined,
  EditOutlined,
  KeyOutlined,
  PlusOutlined,
  SearchOutlined,
  UnorderedListOutlined,
} from '@ant-design/icons';
import {
  Alert,
  App,
  Button,
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
} from 'antd';
import type { ColumnsType } from 'antd/es/table';
import dayjs from 'dayjs';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { apiFetch, config } from '../api';
import type { License, Tier } from '../types';
import { MetricTile } from '../components/MetricTile';
import { SurfacePanel } from '../components/SurfacePanel';
import {
  formatDate,
  showErrorNotification,
  showSuccessNotification,
  statusColor,
  statusIcon,
} from '../utils/licenseHelpers';
import { getOverlayContainer, postShellOverlayState } from '../theme/parentTheme';
import { CheckCircleOutlined } from '@ant-design/icons';

const { Title, Text, Paragraph } = Typography;
const { useBreakpoint } = Grid;

const PAYMENT_INTERVAL_OPTIONS = [
  { value: 'monthly', label: 'Monthly' },
  { value: 'yearly', label: 'Yearly' },
];

const STATUS_FILTERS = [
  { label: 'All', value: '' },
  { label: 'Active', value: 'active' },
  { label: 'Expired', value: 'expired' },
  { label: 'Suspended', value: 'suspended' },
  { label: 'Cancelled', value: 'cancelled' },
];

// ---------------------------------------------------------------------------
// Zod schemas
// ---------------------------------------------------------------------------

const dayjsFuture = z.custom<dayjs.Dayjs>(
  val => dayjs.isDayjs(val) && val.endOf('day').isAfter(dayjs()),
  { message: 'Date must be in the future' },
);

const dayjsAny = z.custom<dayjs.Dayjs>(val => dayjs.isDayjs(val), {
  message: 'Expiry date is required',
});

const createLicenseSchema = z.object({
  customerEmail: z.string().email('Enter a valid email').min(1, 'Email is required'),
  customerName: z.string().optional(),
  role: z.enum(['owner', 'customer']),
  tier: z.string().min(1, 'Tier is required'),
  validUntil: dayjsFuture,
  paymentInterval: z.string().min(1, 'Payment interval is required'),
  notes: z.string().optional(),
});

const editLicenseSchema = z
  .object({
    customerEmail: z.string().email('Enter a valid email').min(1, 'Email is required'),
    customerName: z.string().optional(),
    role: z.enum(['owner', 'customer']),
    tier: z.string().min(1, 'Tier is required'),
    status: z.enum(['active', 'expired', 'suspended', 'cancelled']),
    validUntil: dayjsAny,
    paymentInterval: z.string().min(1, 'Payment interval is required'),
    autoRenewal: z.boolean(),
    maxActivations: z.number().min(1, 'Activation limit is required'),
    notes: z.string().optional(),
  })
  .superRefine((data, ctx) => {
    if (
      data.status === 'active' &&
      data.validUntil &&
      !data.validUntil.endOf('day').isAfter(dayjs())
    ) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['validUntil'],
        message: 'Active licenses must use a future expiry date',
      });
    }
  });

type CreateFormValues = z.infer<typeof createLicenseSchema>;
type EditFormValues = z.infer<typeof editLicenseSchema>;

// ---------------------------------------------------------------------------
// CreateLicenseModal
// ---------------------------------------------------------------------------

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
  const [creating, setCreating] = useState(false);
  const ownerOptionDisabled = ownerLicenseId !== null;

  const {
    register,
    handleSubmit,
    control,
    reset,
    formState: { errors },
  } = useForm<CreateFormValues>({
    resolver: zodResolver(createLicenseSchema),
    defaultValues: {
      role: 'customer',
      tier: tiers[0]?.value ?? 'pro',
      paymentInterval: 'yearly',
    },
  });

  useEffect(() => {
    if (!open) {
      reset({
        role: 'customer',
        tier: tiers[0]?.value ?? 'pro',
        paymentInterval: 'yearly',
      });
    }
  }, [open, reset, tiers]);

  const onSubmit = useCallback(
    async (values: CreateFormValues) => {
      setCreating(true);
      try {
        const data = await apiFetch<{ licenseKey: string }>('/licenses', {
          method: 'POST',
          body: JSON.stringify({
            customerEmail: values.customerEmail,
            customerName: values.customerName ?? '',
            role: values.role,
            tier: values.tier,
            validUntil: values.validUntil.format('YYYY-MM-DD'),
            paymentInterval: values.paymentInterval,
            notes: values.notes ?? '',
          }),
        });

        onCreated(data.licenseKey);
        reset();

        showSuccessNotification(notification, {
          message: 'License created',
          description: "The full key is shown above. Copy it now — it won't be shown again.",
          duration: 6,
        });
      } catch (err) {
        showErrorNotification(notification, {
          message: 'Could not create license',
          description: err instanceof Error ? err.message : 'Unknown error',
        });
      } finally {
        setCreating(false);
      }
    },
    [notification, onCreated, reset],
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
      onOk={() => void handleSubmit(onSubmit)()}
      confirmLoading={creating}
      width={680}
      getContainer={false}
      zIndex={100200}
    >
      <Form layout="vertical">
        <div className="wp-license-server-admin-form-grid">
          <Form.Item
            label="Customer Email"
            validateStatus={errors.customerEmail ? 'error' : ''}
            help={errors.customerEmail?.message}
          >
            <Input {...register('customerEmail')} placeholder="customer@example.com" />
          </Form.Item>

          <Form.Item label="Customer Name">
            <Input {...register('customerName')} placeholder="Jane Smith" />
          </Form.Item>

          <Form.Item
            label="Role"
            validateStatus={errors.role ? 'error' : ''}
            help={errors.role?.message}
          >
            <Controller
              name="role"
              control={control}
              render={({ field }) => (
                <Select
                  {...field}
                  options={[
                    { value: 'customer', label: 'Customer' },
                    {
                      value: 'owner',
                      label: ownerOptionDisabled ? 'Owner (already assigned)' : 'Owner',
                      disabled: ownerOptionDisabled,
                    },
                  ]}
                />
              )}
            />
          </Form.Item>

          <Form.Item
            label="License Tier"
            validateStatus={errors.tier ? 'error' : ''}
            help={errors.tier?.message}
          >
            <Controller
              name="tier"
              control={control}
              render={({ field }) => (
                <Select {...field}>
                  {tiers.map(t => (
                    <Select.Option key={t.value} value={t.value}>
                      {t.label}{' '}
                      <Text type="secondary" style={{ fontSize: 12 }}>
                        ({t.maxActivations} activations)
                      </Text>
                    </Select.Option>
                  ))}
                </Select>
              )}
            />
          </Form.Item>

          <Form.Item
            label="Valid Until"
            validateStatus={errors.validUntil ? 'error' : ''}
            help={errors.validUntil?.message}
          >
            <Controller
              name="validUntil"
              control={control}
              render={({ field }) => (
                <DatePicker
                  {...field}
                  style={{ width: '100%' }}
                  disabledDate={d => d.isBefore(dayjs(), 'day')}
                  format="YYYY-MM-DD"
                />
              )}
            />
          </Form.Item>

          <Form.Item
            label="Payment Interval"
            validateStatus={errors.paymentInterval ? 'error' : ''}
            help={errors.paymentInterval?.message}
          >
            <Controller
              name="paymentInterval"
              control={control}
              render={({ field }) => <Select {...field} options={PAYMENT_INTERVAL_OPTIONS} />}
            />
          </Form.Item>
        </div>

        <Form.Item label="Notes" style={{ marginBottom: 0 }}>
          <Input.TextArea {...register('notes')} rows={3} placeholder="Optional notes…" />
        </Form.Item>
      </Form>
    </Modal>
  );
}

// ---------------------------------------------------------------------------
// EditLicenseModal
// ---------------------------------------------------------------------------

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
  const [saving, setSaving] = useState(false);
  const ownerOptionDisabled = ownerLicenseId !== null && ownerLicenseId !== license?.id;

  const {
    register,
    handleSubmit,
    control,
    reset,
    watch,
    setValue,
    formState: { errors },
  } = useForm<EditFormValues>({
    resolver: zodResolver(editLicenseSchema),
  });

  const statusValue = watch('status');

  useEffect(() => {
    if (!open || !license) {
      reset();
      return;
    }

    reset({
      customerEmail: license.customerEmail,
      customerName: license.customerName,
      role: license.role,
      tier: license.tier,
      status: license.status as EditFormValues['status'],
      validUntil: dayjs(license.validUntil),
      paymentInterval: license.paymentInterval,
      autoRenewal: license.autoRenewal,
      maxActivations: license.maxActivations,
      notes: license.notes ?? '',
    });
  }, [license, open, reset]);

  const onSubmit = useCallback(
    async (values: EditFormValues) => {
      if (!license) {
        return;
      }

      setSaving(true);
      try {
        const originalDate = dayjs(license.validUntil).format('YYYY-MM-DD');
        const selectedDate = values.validUntil.format('YYYY-MM-DD');

        const data = await apiFetch<{ item: License }>(`/licenses/${license.id}`, {
          method: 'PUT',
          body: JSON.stringify({
            customerEmail: values.customerEmail,
            customerName: values.customerName ?? '',
            role: values.role,
            tier: values.tier,
            status: values.status,
            validUntil: selectedDate === originalDate ? license.validUntil : selectedDate,
            paymentInterval: values.paymentInterval,
            autoRenewal: values.autoRenewal,
            maxActivations: values.maxActivations,
            notes: values.notes ?? '',
          }),
        });

        showSuccessNotification(notification, {
          message: 'License updated',
          description: 'The license entry was saved successfully.',
        });
        onSaved(data.item);
      } catch (err) {
        showErrorNotification(notification, {
          message: 'Could not update license',
          description: err instanceof Error ? err.message : 'Unknown error',
        });
      } finally {
        setSaving(false);
      }
    },
    [license, notification, onSaved],
  );

  if (!open || !license) {
    return null;
  }

  return (
    <Modal
      destroyOnClose
      open={open}
      title={license ? `Edit ${license.keyPrefix}…` : 'Edit license'}
      okText="Save changes"
      onCancel={onCancel}
      onOk={() => void handleSubmit(onSubmit)()}
      confirmLoading={saving}
      width={680}
      getContainer={false}
      zIndex={100200}
    >
      <Form layout="vertical">
        <div className="wp-license-server-admin-form-grid">
          <Form.Item
            label="Customer Email"
            validateStatus={errors.customerEmail ? 'error' : ''}
            help={errors.customerEmail?.message}
          >
            <Input {...register('customerEmail')} placeholder="customer@example.com" />
          </Form.Item>

          <Form.Item label="Customer Name">
            <Input {...register('customerName')} placeholder="Jane Smith" />
          </Form.Item>

          <Form.Item
            label="Role"
            validateStatus={errors.role ? 'error' : ''}
            help={errors.role?.message}
          >
            <Controller
              name="role"
              control={control}
              render={({ field }) => (
                <Select
                  {...field}
                  options={[
                    { value: 'customer', label: 'Customer' },
                    {
                      value: 'owner',
                      label: ownerOptionDisabled ? 'Owner (already assigned)' : 'Owner',
                      disabled: ownerOptionDisabled,
                    },
                  ]}
                />
              )}
            />
          </Form.Item>

          <Form.Item
            label="Status"
            validateStatus={errors.status ? 'error' : ''}
            help={errors.status?.message}
          >
            <Controller
              name="status"
              control={control}
              render={({ field }) => (
                <Select
                  {...field}
                  options={[
                    { value: 'active', label: 'Active' },
                    { value: 'expired', label: 'Expired' },
                    { value: 'suspended', label: 'Suspended' },
                    { value: 'cancelled', label: 'Cancelled' },
                  ]}
                />
              )}
            />
          </Form.Item>

          <Form.Item
            label="License Tier"
            validateStatus={errors.tier ? 'error' : ''}
            help={errors.tier?.message}
          >
            <Controller
              name="tier"
              control={control}
              render={({ field }) => (
                <Select
                  {...field}
                  onChange={(value: string) => {
                    field.onChange(value);
                    const tier = tiers.find(item => item.value === value);
                    if (tier) {
                      setValue('maxActivations', tier.maxActivations);
                    }
                  }}
                >
                  {tiers.map(tier => (
                    <Select.Option key={tier.value} value={tier.value}>
                      {tier.label}
                    </Select.Option>
                  ))}
                </Select>
              )}
            />
          </Form.Item>

          <Form.Item
            label="Max Activations"
            tooltip="Override the tier default when this license needs a custom limit."
            validateStatus={errors.maxActivations ? 'error' : ''}
            help={errors.maxActivations?.message}
          >
            <Controller
              name="maxActivations"
              control={control}
              render={({ field: { value, onChange, ...rest } }) => (
                <InputNumber
                  {...rest}
                  value={value}
                  onChange={v => onChange(v ?? 1)}
                  min={1}
                  style={{ width: '100%' }}
                />
              )}
            />
          </Form.Item>

          <Form.Item
            label="Valid Until"
            validateStatus={errors.validUntil ? 'error' : ''}
            help={errors.validUntil?.message}
          >
            <Controller
              name="validUntil"
              control={control}
              render={({ field }) => (
                <DatePicker
                  {...field}
                  style={{ width: '100%' }}
                  format="YYYY-MM-DD"
                  disabledDate={date =>
                    statusValue === 'active' ? date.isBefore(dayjs(), 'day') : false
                  }
                />
              )}
            />
          </Form.Item>

          <Form.Item
            label="Payment Interval"
            validateStatus={errors.paymentInterval ? 'error' : ''}
            help={errors.paymentInterval?.message}
          >
            <Controller
              name="paymentInterval"
              control={control}
              render={({ field }) => <Select {...field} options={PAYMENT_INTERVAL_OPTIONS} />}
            />
          </Form.Item>

          <Form.Item
            label="Auto Renewal"
            tooltip="Disable this for manually managed or one-off licenses."
            validateStatus={errors.autoRenewal ? 'error' : ''}
            help={errors.autoRenewal?.message}
          >
            <Controller
              name="autoRenewal"
              control={control}
              render={({ field: { value, onChange, ref: _ref, ...rest } }) => (
                <Switch {...rest} checked={value} onChange={onChange} />
              )}
            />
          </Form.Item>
        </div>

        <Form.Item label="Notes">
          <Input.TextArea {...register('notes')} rows={4} placeholder="Internal notes for this license…" />
        </Form.Item>
      </Form>
    </Modal>
  );
}

// ---------------------------------------------------------------------------
// LicensesTable
// ---------------------------------------------------------------------------

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
  const tierMap = Object.fromEntries(tiers.map(t => [t.value, t.label]));
  const [searchQuery, setSearchQuery] = useState('');
  const [pageSize, setPageSize] = useState(30);
  const [currentPage, setCurrentPage] = useState(1);

  const filteredLicenses = useMemo(() => {
    const query = searchQuery.trim().toLowerCase();
    if (!query) {
      return licenses;
    }

    return licenses.filter(license =>
      [
        license.keyPrefix,
        license.customerName,
        license.customerEmail,
        license.role,
        license.tier,
        license.status,
        license.paymentInterval,
        tierMap[license.tier] ?? '',
      ]
        .join(' ')
        .toLowerCase()
        .includes(query),
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
      title: 'Key Prefix',
      dataIndex: 'keyPrefix',
      key: 'keyPrefix',
      render: (v: string) => (
        <Text code style={{ fontSize: 12 }}>
          {v}…
        </Text>
      ),
    },
    {
      title: 'Customer',
      key: 'customer',
      render: (_: unknown, r: License) => (
        <Space direction="vertical" size={0}>
          <Text strong style={{ fontSize: 13 }}>
            {r.customerName || '—'}
          </Text>
          <Text type="secondary" style={{ fontSize: 12 }}>
            {r.customerEmail}
          </Text>
        </Space>
      ),
    },
    {
      title: 'Tier',
      dataIndex: 'tier',
      key: 'tier',
      render: (v: string) => tierMap[v] ?? v,
    },
    {
      title: 'Role',
      dataIndex: 'role',
      key: 'role',
      render: (v: License['role']) => (
        <Tag color={v === 'owner' ? 'purple' : 'blue'}>{v === 'owner' ? 'Owner' : 'Customer'}</Tag>
      ),
    },
    {
      title: 'Status',
      dataIndex: 'status',
      key: 'status',
      render: (v: string) => (
        <Tag color={statusColor(v)} icon={statusIcon(v)}>
          {v.charAt(0).toUpperCase() + v.slice(1)}
        </Tag>
      ),
    },
    {
      title: 'Activations',
      key: 'activations',
      render: (_: unknown, r: License) => (
        <Text>
          {r.currentActivations} / {r.maxActivations}
        </Text>
      ),
    },
    {
      title: 'Valid Until',
      dataIndex: 'validUntil',
      key: 'validUntil',
      render: (v: string) => formatDate(v),
    },
    {
      title: 'Actions',
      key: 'actions',
      align: 'right',
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
            onChange={event => setSearchQuery(event.target.value)}
            placeholder="Search licenses"
            prefix={<SearchOutlined />}
            size="large"
            className="wp-license-server-admin-toolbar__search"
          />
          <Select
            value={pageSize}
            onChange={value => setPageSize(value)}
            size="large"
            style={{ width: 128 }}
            options={[
              { value: 30, label: '30 / page' },
              { value: 20, label: '20 / page' },
              { value: 10, label: '10 / page' },
              { value: 5, label: '5 / page' },
            ]}
          />
          <Select
            value={statusFilter}
            onChange={onStatusFilterChange}
            size="large"
            style={{ width: 140 }}
            options={STATUS_FILTERS.map(s => ({ value: s.value, label: s.label }))}
          />
        </div>
      </div>
      {loading && licenses.length === 0 ? (
        <Flex justify="center" style={{ padding: '40px 0' }}>
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
            scroll={{ x: 'max-content' }}
            locale={{ emptyText: 'No licenses found.' }}
            expandable={{
              expandedRowRender: r =>
                r.notes ? (
                  <Text type="secondary" style={{ fontSize: 12 }}>
                    {r.notes}
                  </Text>
                ) : null,
              rowExpandable: r => !!r.notes,
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
            onChange={page => setCurrentPage(page)}
          />
        </Flex>
      )}
    </div>
  );
}

// ---------------------------------------------------------------------------
// LicensesPage
// ---------------------------------------------------------------------------

export function LicensesPage() {
  const { modal, notification } = App.useApp();
  const screens = useBreakpoint();

  const [licenses, setLicenses] = useState<License[]>([]);
  const [tiers, setTiers] = useState<Tier[]>(config.tiers ?? []);
  const [ownerLicenseId, setOwnerLicenseId] = useState<number | null>(null);
  const [loading, setLoading] = useState(true);
  const [statusFilter, setStatusFilter] = useState(config.status ?? '');
  const [newLicenseKey, setNewLicenseKey] = useState<string | null>(null);
  const [createModalOpen, setCreateModalOpen] = useState(false);
  const [editingLicense, setEditingLicense] = useState<License | null>(null);
  const [confirmOverlayCount, setConfirmOverlayCount] = useState(0);
  const overlayActive = createModalOpen || editingLicense !== null || confirmOverlayCount > 0;

  const fetchLicenses = useCallback(async () => {
    setLoading(true);
    try {
      const data = await apiFetch<{
        items: License[];
        tiers: Tier[];
        ownerLicenseId?: number | null;
      }>(`/licenses${statusFilter ? `?status=${encodeURIComponent(statusFilter)}` : ''}`);
      setLicenses(data.items);
      if (data.tiers) setTiers(data.tiers);
      setOwnerLicenseId(typeof data.ownerLicenseId === 'number' ? data.ownerLicenseId : null);
    } catch (err) {
      showErrorNotification(notification, {
        message: 'Failed to load licenses',
        description: err instanceof Error ? err.message : 'Unknown error',
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
    [],
  );

  const markConfirmOverlayClosed = useCallback(() => {
    setConfirmOverlayCount(count => Math.max(0, count - 1));
  }, []);

  const handleDelete = useCallback(
    (id: number) => {
      setConfirmOverlayCount(count => count + 1);
      modal.confirm({
        title: 'Delete license?',
        content: 'This action cannot be undone.',
        okText: 'Delete',
        okButtonProps: { danger: true },
        getContainer: getOverlayContainer,
        afterClose: markConfirmOverlayClosed,
        onOk: async () => {
          try {
            await apiFetch(`/licenses/${id}`, { method: 'DELETE' });
            showSuccessNotification(notification, { message: 'License deleted' });
            void fetchLicenses();
          } catch (err) {
            showErrorNotification(notification, {
              message: 'Could not delete license',
              description: err instanceof Error ? err.message : 'Unknown error',
            });
            throw err;
          }
        },
      });
    },
    [modal, notification, fetchLicenses, markConfirmOverlayClosed],
  );

  const handleDeactivateAll = useCallback(
    (id: number) => {
      setConfirmOverlayCount(count => count + 1);
      modal.confirm({
        title: 'Deactivate all domains for this license?',
        okText: 'Deactivate All',
        getContainer: getOverlayContainer,
        afterClose: markConfirmOverlayClosed,
        onOk: async () => {
          try {
            const data = await apiFetch<{ deactivated: number }>(
              `/licenses/${id}/deactivate-all`,
              { method: 'POST' },
            );
            showSuccessNotification(notification, {
              message: `${data.deactivated} activation(s) removed`,
            });
            void fetchLicenses();
          } catch (err) {
            showErrorNotification(notification, {
              message: 'Could not deactivate activations',
              description: err instanceof Error ? err.message : 'Unknown error',
            });
            throw err;
          }
        },
      });
    },
    [modal, notification, fetchLicenses, markConfirmOverlayClosed],
  );

  const handleLicenseCreated = useCallback(
    (key: string) => {
      setCreateModalOpen(false);
      setNewLicenseKey(key);
      void fetchLicenses();
    },
    [fetchLicenses],
  );

  const handleLicenseSaved = useCallback(
    (license: License) => {
      setEditingLicense(null);
      setLicenses(current => current.map(item => (item.id === license.id ? license : item)));
      void fetchLicenses();
    },
    [fetchLicenses],
  );

  const activeLicenses = licenses.filter(license => license.status === 'active').length;
  const ownerLicenses = ownerLicenseId === null ? 0 : 1;
  const currentActivations = licenses.reduce(
    (total, license) => total + license.currentActivations,
    0,
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
              <Button
                type="primary"
                icon={<PlusOutlined />}
                onClick={() => setCreateModalOpen(true)}
              >
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
          title={
            <Title level={4} style={{ margin: 0, fontSize: 20 }}>
              License Overview
            </Title>
          }
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
