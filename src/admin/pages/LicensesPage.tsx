import {
  DeleteOutlined,
  DisconnectOutlined,
  EditOutlined,
  KeyOutlined,
  PlusOutlined,
  ReloadOutlined,
  SafetyCertificateOutlined,
  SearchOutlined,
  SettingOutlined,
  UnorderedListOutlined,
  WarningOutlined,
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
import { useSaveDevMode } from '../hooks/useLicenseServerSettings';
import {
  formatDate,
  showErrorNotification,
  showSuccessNotification,
  statusColor,
  statusIcon,
} from '../utils/licenseHelpers';
import { getOverlayContainer, injectParentShellOverlay } from '../theme/parentTheme';
import { CheckCircleOutlined } from '@ant-design/icons';
import { __, _x, _n } from '../../utils/i18n';

const { Title, Text, Paragraph } = Typography;
const { useBreakpoint } = Grid;

const PAYMENT_INTERVAL_OPTIONS = [
  { value: 'monthly', label: __('Monthly', 'wp-license-server') },
  { value: 'yearly', label: __('Yearly', 'wp-license-server') },
];

const STATUS_FILTERS = [
  { label: __('All', 'wp-license-server'), value: '' },
  { label: __('Active', 'wp-license-server'), value: 'active' },
  { label: __('Expired', 'wp-license-server'), value: 'expired' },
  { label: __('Suspended', 'wp-license-server'), value: 'suspended' },
  { label: __('Cancelled', 'wp-license-server'), value: 'cancelled' },
];

// ---------------------------------------------------------------------------
// Zod schemas
// ---------------------------------------------------------------------------

const dayjsFuture = z.custom<dayjs.Dayjs>(
  val => dayjs.isDayjs(val) && val.endOf('day').isAfter(dayjs()),
  { message: __('Date must be in the future', 'wp-license-server') },
);

const dayjsAny = z.custom<dayjs.Dayjs>(val => dayjs.isDayjs(val), {
  message: __('Expiry date is required', 'wp-license-server'),
});

const createLicenseSchema = z.object({
  customerEmail: z.string().email(__('Enter a valid email', 'wp-license-server')).min(1, __('Email is required', 'wp-license-server')),
  customerName: z.string().optional(),
  role: z.enum(['owner', 'customer']),
  tier: z.string().min(1, __('Tier is required', 'wp-license-server')),
  validUntil: dayjsFuture,
  paymentInterval: z.string().min(1, __('Payment interval is required', 'wp-license-server')),
  notes: z.string().optional(),
});

const editLicenseSchema = z
  .object({
    customerEmail: z.string().email(__('Enter a valid email', 'wp-license-server')).min(1, __('Email is required', 'wp-license-server')),
    customerName: z.string().optional(),
    role: z.enum(['owner', 'customer']),
    tier: z.string().min(1, __('Tier is required', 'wp-license-server')),
    status: z.enum(['active', 'expired', 'suspended', 'cancelled']),
    validUntil: dayjsAny,
    paymentInterval: z.string().min(1, __('Payment interval is required', 'wp-license-server')),
    autoRenewal: z.boolean(),
    maxActivations: z.number().min(1, __('Activation limit is required', 'wp-license-server')),
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
        message: __('Active licenses must use a future expiry date', 'wp-license-server'),
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
    handleSubmit,
    control,
    reset,
    formState: { errors },
  } = useForm<CreateFormValues>({
    resolver: zodResolver(createLicenseSchema),
    mode: 'onBlur',
    defaultValues: {
      customerEmail: '',
      customerName: '',
      role: 'customer',
      tier: tiers[0]?.value ?? 'pro',
      paymentInterval: 'yearly',
      notes: '',
    },
  });

  useEffect(() => {
    if (!open) {
      reset({
        customerEmail: '',
        customerName: '',
        role: 'customer',
        tier: tiers[0]?.value ?? 'pro',
        paymentInterval: 'yearly',
        notes: '',
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
          message: __('License created', 'wp-license-server'),
          description: __("The full key is shown above. Copy it now — it won't be shown again.", 'wp-license-server'),
          duration: 6,
        });
      } catch (err) {
        showErrorNotification(notification, {
          message: __('Could not create license', 'wp-license-server'),
          description: err instanceof Error ? err.message : __('Unknown error', 'wp-license-server'),
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
      title={__('Create license', 'wp-license-server')}
      okText={__('Create license', 'wp-license-server')}
      onCancel={onCancel}
      onOk={() => void handleSubmit(onSubmit)()}
      confirmLoading={creating}
      width={680}
      getContainer={false}
      mask={false}
      zIndex={100200}
    >
      <Form layout="vertical">
        <div className="wp-license-server-admin-form-grid">
          <Form.Item
            label={__('Customer Email', 'wp-license-server')}
            validateStatus={errors.customerEmail ? 'error' : ''}
            help={errors.customerEmail?.message}
          >
            <Controller
              name="customerEmail"
              control={control}
              render={({ field }) => (
                <Input {...field} placeholder="customer@example.com" autoComplete="off" />
              )}
            />
          </Form.Item>

          <Form.Item label={__('Customer Name', 'wp-license-server')}>
            <Controller
              name="customerName"
              control={control}
              render={({ field }) => <Input {...field} placeholder="Jane Smith" />}
            />
          </Form.Item>

          <Form.Item
            label={__('Role', 'wp-license-server')}
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
                    { value: 'customer', label: __('Customer', 'wp-license-server') },
                    {
                      value: 'owner',
                      label: ownerOptionDisabled ? __('Owner (already assigned)', 'wp-license-server') : __('Owner', 'wp-license-server'),
                      disabled: ownerOptionDisabled,
                    },
                  ]}
                />
              )}
            />
          </Form.Item>

          <Form.Item
            label={__('License Tier', 'wp-license-server')}
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
                        ({t.maxActivations} {__('activations', 'wp-license-server')})
                      </Text>
                    </Select.Option>
                  ))}
                </Select>
              )}
            />
          </Form.Item>

          <Form.Item
            label={__('Valid Until', 'wp-license-server')}
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
            label={__('Payment Interval', 'wp-license-server')}
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

        <Form.Item label={__('Notes', 'wp-license-server')} style={{ marginBottom: 0 }}>
          <Controller
            name="notes"
            control={control}
            render={({ field }) => <Input.TextArea {...field} rows={3} placeholder={__('Optional notes…', 'wp-license-server')} />}
          />
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
    handleSubmit,
    control,
    reset,
    watch,
    setValue,
    formState: { errors },
  } = useForm<EditFormValues>({
    resolver: zodResolver(editLicenseSchema),
    mode: 'onBlur',
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
          message: __('License updated', 'wp-license-server'),
          description: __('The license entry was saved successfully.', 'wp-license-server'),
        });
        onSaved(data.item);
      } catch (err) {
        showErrorNotification(notification, {
          message: __('Could not update license', 'wp-license-server'),
          description: err instanceof Error ? err.message : __('Unknown error', 'wp-license-server'),
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
      title={license ? __('Edit', 'wp-license-server') + ' ' + license.keyPrefix + '…' : __('Edit license', 'wp-license-server')}
      okText={__('Save changes', 'wp-license-server')}
      onCancel={onCancel}
      onOk={() => void handleSubmit(onSubmit)()}
      confirmLoading={saving}
      width={680}
      getContainer={false}
      mask={false}
      zIndex={100200}
    >
      <Form layout="vertical">
        <div className="wp-license-server-admin-form-grid">
          <Form.Item
            label={__('Customer Email', 'wp-license-server')}
            validateStatus={errors.customerEmail ? 'error' : ''}
            help={errors.customerEmail?.message}
          >
            <Controller
              name="customerEmail"
              control={control}
              render={({ field }) => (
                <Input {...field} placeholder="customer@example.com" autoComplete="off" />
              )}
            />
          </Form.Item>

          <Form.Item label={__('Customer Name', 'wp-license-server')}>
            <Controller
              name="customerName"
              control={control}
              render={({ field }) => <Input {...field} placeholder="Jane Smith" />}
            />
          </Form.Item>

          <Form.Item
            label={__('Role', 'wp-license-server')}
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
                    { value: 'customer', label: __('Customer', 'wp-license-server') },
                    {
                      value: 'owner',
                      label: ownerOptionDisabled ? __('Owner (already assigned)', 'wp-license-server') : __('Owner', 'wp-license-server'),
                      disabled: ownerOptionDisabled,
                    },
                  ]}
                />
              )}
            />
          </Form.Item>

          <Form.Item
            label={__('Status', 'wp-license-server')}
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
                    { value: 'active', label: __('Active', 'wp-license-server') },
                    { value: 'expired', label: __('Expired', 'wp-license-server') },
                    { value: 'suspended', label: __('Suspended', 'wp-license-server') },
                    { value: 'cancelled', label: __('Cancelled', 'wp-license-server') },
                  ]}
                />
              )}
            />
          </Form.Item>

          <Form.Item
            label={__('License Tier', 'wp-license-server')}
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
            label={__('Max Activations', 'wp-license-server')}
            tooltip={__('Override the tier default when this license needs a custom limit.', 'wp-license-server')}
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
            label={__('Valid Until', 'wp-license-server')}
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
            label={__('Payment Interval', 'wp-license-server')}
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
            label={__('Auto Renewal', 'wp-license-server')}
            tooltip={__('Disable this for manually managed or one-off licenses.', 'wp-license-server')}
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

        <Form.Item label={__('Notes', 'wp-license-server')}>
          <Controller
            name="notes"
            control={control}
            render={({ field }) => <Input.TextArea {...field} rows={4} placeholder={__('Internal notes for this license…', 'wp-license-server')} />}
          />
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
  onCreateClick: () => void;
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
  onRefresh,
  onCreateClick,
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
      title: __('Key Prefix', 'wp-license-server'),
      dataIndex: 'keyPrefix',
      key: 'keyPrefix',
      render: (v: string) => (
        <Text code style={{ fontSize: 12 }}>
          {v}…
        </Text>
      ),
    },
    {
      title: __('Customer', 'wp-license-server'),
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
      title: __('Tier', 'wp-license-server'),
      dataIndex: 'tier',
      key: 'tier',
      render: (v: string) => tierMap[v] ?? v,
    },
    {
      title: __('Role', 'wp-license-server'),
      dataIndex: 'role',
      key: 'role',
      render: (v: License['role']) => (
        <Tag color={v === 'owner' ? 'purple' : 'blue'}>{v === 'owner' ? __('Owner', 'wp-license-server') : __('Customer', 'wp-license-server')}</Tag>
      ),
    },
    {
      title: __('Status', 'wp-license-server'),
      dataIndex: 'status',
      key: 'status',
      render: (v: string) => (
        <Tag color={statusColor(v)} icon={statusIcon(v)}>
          {v.charAt(0).toUpperCase() + v.slice(1)}
        </Tag>
      ),
    },
    {
      title: __('Activations', 'wp-license-server'),
      key: 'activations',
      render: (_: unknown, r: License) => (
        <Text>
          {r.currentActivations} / {r.maxActivations}
        </Text>
      ),
    },
    {
      title: __('Valid Until', 'wp-license-server'),
      dataIndex: 'validUntil',
      key: 'validUntil',
      render: (v: string) => formatDate(v),
    },
    {
      title: __('Actions', 'wp-license-server'),
      key: 'actions',
      align: 'right',
      render: (_: unknown, r: License) => (
        <Space style={{ marginBottom: 8 }}>
          <Tooltip title={__('Edit license', 'wp-license-server')}>
            <Button size="middle" icon={<EditOutlined />} onClick={() => onEdit(r)}>
              {__('Edit', 'wp-license-server')}
            </Button>
          </Tooltip>
          {r.currentActivations > 0 && (
            <Tooltip title={__('Deactivate all domains', 'wp-license-server')}>
              <Button
                size="middle"
                icon={<DisconnectOutlined />}
                onClick={() => onDeactivateAll(r.id)}
              >
                {__('Deactivate All', 'wp-license-server')}
              </Button>
            </Tooltip>
          )}
          <Tooltip title={__('Delete license', 'wp-license-server')}>
            <Button size="middle" danger icon={<DeleteOutlined />} onClick={() => onDelete(r.id)} />
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
            placeholder={__('Search licenses', 'wp-license-server')}
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
              { value: 30, label: __('30 / page', 'wp-license-server') },
              { value: 20, label: __('20 / page', 'wp-license-server') },
              { value: 10, label: __('10 / page', 'wp-license-server') },
              { value: 5, label: __('5 / page', 'wp-license-server') },
            ]}
          />
          <Select
            value={statusFilter}
            onChange={onStatusFilterChange}
            size="large"
            style={{ width: 140 }}
            options={STATUS_FILTERS.map(s => ({ value: s.value, label: s.label }))}
          />
          <Button
            type="primary"
            icon={<PlusOutlined />}
            size="large"
            onClick={onCreateClick}
          >
            {__('Create license', 'wp-license-server')}
          </Button>
          <Button
            icon={<ReloadOutlined />}
            size="large"
            loading={loading}
            onClick={onRefresh}
          >
            {__('Refresh', 'wp-license-server')}
          </Button>
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
            locale={{ emptyText: __('No licenses found.', 'wp-license-server') }}
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
        message: __('Failed to load licenses', 'wp-license-server'),
        description: err instanceof Error ? err.message : __('Unknown error', 'wp-license-server'),
      });
    } finally {
      setLoading(false);
    }
  }, [statusFilter, notification]);

  useEffect(() => {
    void fetchLicenses();
  }, [fetchLicenses]);

  const handleCloseAllModals = useCallback(() => {
    setCreateModalOpen(false);
    setEditingLicense(null);
    setConfirmOverlayCount(0);
  }, []);

  useEffect(() => {
    if (!overlayActive) return;

    // Inject a direct DOM overlay into the parent shell so the sidebar and
    // navbar are dimmed behind a single seamless backdrop. Also elevates the
    // content slot synchronously so the iframe/modal floats above the overlay.
    const cleanup = injectParentShellOverlay(handleCloseAllModals);
    return () => {
      cleanup?.();
    };
  }, [overlayActive, handleCloseAllModals]);

  const markConfirmOverlayClosed = useCallback(() => {
    setConfirmOverlayCount(count => Math.max(0, count - 1));
  }, []);

  const handleDelete = useCallback(
    (id: number) => {
      setConfirmOverlayCount(count => count + 1);
      modal.confirm({
        title: __('Delete license?', 'wp-license-server'),
        content: __('This action cannot be undone.', 'wp-license-server'),
        okText: __('Delete', 'wp-license-server'),
        okButtonProps: { danger: true },
        getContainer: getOverlayContainer,
        afterClose: markConfirmOverlayClosed,
        onOk: async () => {
          try {
            await apiFetch(`/licenses/${id}`, { method: 'DELETE' });
            showSuccessNotification(notification, { message: __('License deleted', 'wp-license-server') });
            void fetchLicenses();
          } catch (err) {
            showErrorNotification(notification, {
              message: __('Could not delete license', 'wp-license-server'),
              description: err instanceof Error ? err.message : __('Unknown error', 'wp-license-server'),
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
        title: __('Deactivate all domains for this license?', 'wp-license-server'),
        okText: __('Deactivate All', 'wp-license-server'),
        getContainer: getOverlayContainer,
        afterClose: markConfirmOverlayClosed,
        onOk: async () => {
          try {
            const data = await apiFetch<{ deactivated: number }>(
              `/licenses/${id}/deactivate-all`,
              { method: 'POST' },
            );
            showSuccessNotification(notification, {
              message: _n('%d activation removed', '%d activations removed', data.deactivated, 'wp-license-server').replace('%d', String(data.deactivated)),
            });
            void fetchLicenses();
          } catch (err) {
            showErrorNotification(notification, {
              message: __('Could not deactivate activations', 'wp-license-server'),
              description: err instanceof Error ? err.message : __('Unknown error', 'wp-license-server'),
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

  const [developmentMode, setDevelopmentMode] = useState(config.developmentMode ?? false);
  const { save: saveDevMode, saving: savingDevMode } = useSaveDevMode();

  const handleDevModeToggle = useCallback(
    async (enabled: boolean) => {
      setDevelopmentMode(enabled);
      try {
        await saveDevMode(enabled);
        showSuccessNotification(notification, {
          message: enabled ? __('Development mode enabled', 'wp-license-server') : __('Development mode disabled', 'wp-license-server'),
          description: enabled
            ? __('Private IP domain validation is now bypassed.', 'wp-license-server')
            : __('Domain validation is enforcing public IPs only.', 'wp-license-server'),
        });
      } catch (err) {
        setDevelopmentMode(!enabled); // rollback
        showErrorNotification(notification, {
          message: __('Could not update development mode', 'wp-license-server'),
          description: err instanceof Error ? err.message : __('Unknown error', 'wp-license-server'),
        });
      }
    },
    [saveDevMode, notification],
  );

  const activeLicenses = licenses.filter(license => license.status === 'active').length;
  const ownerLicenses = ownerLicenseId === null ? 0 : 1;
  const currentActivations = licenses.reduce(
    (total, license) => total + license.currentActivations,
    0,
  );

  return (
    <main className="wp-react-ui-page-canvas wp-license-server-admin-shell">
      {/* Local iframe backdrop — rendered in the same React pass as the modal
          open state, so it appears atomically with the parent-shell overlay
          injected in the useEffect. No AntD mask animation to cause stagger. */}
      {overlayActive && (
        <div
          aria-hidden="true"
          onClick={handleCloseAllModals}
          style={{
            position: 'fixed',
            inset: 0,
            background: 'rgba(0, 0, 0, 0.45)',
            zIndex: 100190,
            cursor: 'default',
          }}
        />
      )}
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
              <div className="wp-license-server-admin-eyebrow">{__('Central license operations', 'wp-license-server')}</div>
              <Title
                level={2}
                className="wp-react-ui-page-intro__title"
                style={{ marginBottom: 6, fontSize: screens.md ? 30 : 24 }}
              >
                {__('License Manager', 'wp-license-server')}
              </Title>
              <Paragraph
                type="secondary"
                className="wp-react-ui-page-intro__description"
                style={{ marginBottom: 0, maxWidth: 760, fontSize: 14 }}
              >
                {__('Issue, monitor, and revoke licenses for all plugin customers.', 'wp-license-server')}
              </Paragraph>
            </div>
          </Flex>
        </div>

        {newLicenseKey && (
          <Alert
            type="success"
            showIcon
            closable
            onClose={() => setNewLicenseKey(null)}
            style={{ marginBottom: 24 }}
            message={__('License created — copy the full key now', 'wp-license-server')}
            description={
              <Space>
                <Text strong>{__('Full key:', 'wp-license-server')}</Text>
                <Text code copyable={{ text: newLicenseKey }} style={{ fontSize: 13 }}>
                  {newLicenseKey}
                </Text>
              </Space>
            }
          />
        )}

        <div className="wp-license-server-admin-metric-grid">
          <MetricTile
            label={__('Total licenses', 'wp-license-server')}
            value={licenses.length}
            meta={__('All keys currently stored on this server.', 'wp-license-server')}
            icon={<KeyOutlined />}
            accent="primary"
          />
          <MetricTile
            label={__('Active now', 'wp-license-server')}
            value={activeLicenses}
            meta={__('Licenses that can currently activate clients.', 'wp-license-server')}
            icon={<CheckCircleOutlined />}
            accent="success"
          />
          <MetricTile
            label={__('Owner keys', 'wp-license-server')}
            value={ownerLicenses}
            meta={__('Owner licenses can view the full support inbox.', 'wp-license-server')}
            icon={<UnorderedListOutlined />}
          />
          <MetricTile
            label={__('Client activations', 'wp-license-server')}
            value={currentActivations}
            meta={__('Combined active site installations across all keys.', 'wp-license-server')}
            icon={<DisconnectOutlined />}
          />
          <MetricTile
            label={__('Encryption Key', 'wp-license-server')}
            value={config.encryptionKeySource === 'constant' ? __('wp-config.php', 'wp-license-server') : __('Database', 'wp-license-server')}
            meta={
              config.encryptionKeySource === 'constant'
                ? __('Secure', 'wp-license-server')
                : __('Move to wp-config.php for production', 'wp-license-server')
            }
            icon={<SafetyCertificateOutlined />}
            accent={config.encryptionKeySource === 'constant' ? 'success' : 'warning'}
          />
        </div>

        <SurfacePanel
          className="wp-license-server-admin-overview-panel"
          icon={<UnorderedListOutlined />}
          title={
            <Title level={4} style={{ margin: 0, fontSize: 20 }}>
              {__('License Overview', 'wp-license-server')}
            </Title>
          }
          description={__('Monitor every issued key, edit customer metadata, and manage activations without leaving the server console.', 'wp-license-server')}
        >
          <LicensesTable
            licenses={licenses}
            tiers={tiers}
            loading={loading}
            statusFilter={statusFilter}
            onStatusFilterChange={setStatusFilter}
            onRefresh={() => void fetchLicenses()}
            onCreateClick={() => setCreateModalOpen(true)}
            onEdit={setEditingLicense}
            onDelete={handleDelete}
            onDeactivateAll={handleDeactivateAll}
          />
        </SurfacePanel>

        <SurfacePanel
          className="wp-license-server-admin-dev-panel"
          icon={<SettingOutlined />}
          style={{ marginTop: 32 }}
          title={
            <Flex align="center" gap={8}>
              <Title level={4} style={{ margin: 0, fontSize: 20 }}>
                {__('Development Settings', 'wp-license-server')}
              </Title>
            </Flex>
          }
          description={__('Configure server behaviour for local development and testing.', 'wp-license-server')}
        >
          <Flex vertical gap={8} style={{ marginBottom: 8 }}>
            <Flex align="center" justify="space-between">
              <Flex vertical gap={2}>
                <Text strong>{__('Development Mode', 'wp-license-server')}</Text>
                <Text type="secondary" style={{ fontSize: 12 }}>
                  {__('Bypass private IP domain validation so client sites on localhost, private IPs, or .local domains can activate licenses.', 'wp-license-server')}
                </Text>
              </Flex>
              <Switch
                checked={developmentMode}
                loading={savingDevMode}
                onChange={handleDevModeToggle}
              />
            </Flex>
            {developmentMode && (
              <Alert
                type="warning"
                showIcon
                icon={<WarningOutlined />}
                message={__('SSRF protection bypassed', 'wp-license-server')}
                description={
                  <Text style={{ fontSize: 12 }}>
                    {__('Webhook targets and activation domains on private or reserved IP addresses are allowed. Only use this in local development environments.', 'wp-license-server')}
                  </Text>
                }
                style={{ marginTop: 8 }}
              />
            )}
          </Flex>
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
