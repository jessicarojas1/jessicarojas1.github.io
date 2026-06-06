import { lazy, Suspense, type ReactNode } from 'react';
import { Route, Routes } from 'react-router-dom';
import { Layout } from './components/Layout';
import { ProtectedRoute } from './components/ProtectedRoute';
import type { Capability } from './lib/rbac';

const LoginPage = lazy(() => import('./pages/LoginPage'));
const DashboardPage = lazy(() => import('./pages/DashboardPage'));
const DocumentationPage = lazy(() => import('./pages/docs/DocumentationPage'));
const NotFoundPage = lazy(() => import('./pages/NotFoundPage'));

const NcrListPage = lazy(() => import('./pages/nonconformances/NcrListPage'));
const NcrDetailPage = lazy(() => import('./pages/nonconformances/NcrDetailPage'));
const CapaListPage = lazy(() => import('./pages/capa/CapaListPage'));
const CapaDetailPage = lazy(() => import('./pages/capa/CapaDetailPage'));
const DocumentListPage = lazy(() => import('./pages/documents/DocumentListPage'));
const DocumentDetailPage = lazy(() => import('./pages/documents/DocumentDetailPage'));
const AuditListPage = lazy(() => import('./pages/audits/AuditListPage'));
const AuditDetailPage = lazy(() => import('./pages/audits/AuditDetailPage'));
const SupplierListPage = lazy(() => import('./pages/suppliers/SupplierListPage'));
const SupplierDetailPage = lazy(() => import('./pages/suppliers/SupplierDetailPage'));
const CalibrationListPage = lazy(() => import('./pages/calibration/CalibrationListPage'));
const CalibrationDetailPage = lazy(() => import('./pages/calibration/CalibrationDetailPage'));
const TrainingListPage = lazy(() => import('./pages/training/TrainingListPage'));
const ChangeListPage = lazy(() => import('./pages/changes/ChangeListPage'));
const ChangeDetailPage = lazy(() => import('./pages/changes/ChangeDetailPage'));
const RiskListPage = lazy(() => import('./pages/risks/RiskListPage'));
const InspectionListPage = lazy(() => import('./pages/inspections/InspectionListPage'));
const InspectionDetailPage = lazy(() => import('./pages/inspections/InspectionDetailPage'));
const MgmtReviewListPage = lazy(() => import('./pages/mgmtReviews/MgmtReviewListPage'));
const MgmtReviewDetailPage = lazy(() => import('./pages/mgmtReviews/MgmtReviewDetailPage'));
const ComplaintListPage = lazy(() => import('./pages/complaints/ComplaintListPage'));
const ComplaintDetailPage = lazy(() => import('./pages/complaints/ComplaintDetailPage'));
const UsersPage = lazy(() => import('./pages/admin/UsersPage'));
const RolesPage = lazy(() => import('./pages/admin/RolesPage'));
const AuditTrailPage = lazy(() => import('./pages/admin/AuditTrailPage'));
const AnalyticsPage = lazy(() => import('./pages/AnalyticsPage'));

function PageFallback() {
  return (
    <div className="loading-block" style={{ minHeight: '50vh' }}>
      <span className="spinner spinner--lg" />
    </div>
  );
}

function Guard({ capability, children }: { capability?: Capability; children: ReactNode }) {
  return <ProtectedRoute capability={capability}>{children}</ProtectedRoute>;
}

export function AppRouter() {
  return (
    <Suspense fallback={<PageFallback />}>
      <Routes>
        <Route path="/login" element={<LoginPage />} />

        <Route
          element={
            <Guard>
              <Layout />
            </Guard>
          }
        >
          <Route index element={<DashboardPage />} />

          <Route
            path="docs"
            element={<Guard capability="docs.read"><DocumentationPage /></Guard>}
          />

          <Route
            path="analytics"
            element={<Guard capability="ncr.read"><AnalyticsPage /></Guard>}
          />

          <Route path="nonconformances">
            <Route index element={<Guard capability="ncr.read"><NcrListPage /></Guard>} />
            <Route path=":id" element={<Guard capability="ncr.read"><NcrDetailPage /></Guard>} />
          </Route>

          <Route path="capa">
            <Route index element={<Guard capability="capa.read"><CapaListPage /></Guard>} />
            <Route path=":id" element={<Guard capability="capa.read"><CapaDetailPage /></Guard>} />
          </Route>

          <Route path="documents">
            <Route index element={<Guard capability="documents.read"><DocumentListPage /></Guard>} />
            <Route path=":id" element={<Guard capability="documents.read"><DocumentDetailPage /></Guard>} />
          </Route>

          <Route path="audits">
            <Route index element={<Guard capability="audits.read"><AuditListPage /></Guard>} />
            <Route path=":id" element={<Guard capability="audits.read"><AuditDetailPage /></Guard>} />
          </Route>

          <Route path="suppliers">
            <Route index element={<Guard capability="suppliers.read"><SupplierListPage /></Guard>} />
            <Route path=":id" element={<Guard capability="suppliers.read"><SupplierDetailPage /></Guard>} />
          </Route>

          <Route path="calibration">
            <Route index element={<Guard capability="calibration.read"><CalibrationListPage /></Guard>} />
            <Route path=":id" element={<Guard capability="calibration.read"><CalibrationDetailPage /></Guard>} />
          </Route>

          <Route
            path="training"
            element={<Guard capability="training.read"><TrainingListPage /></Guard>}
          />

          <Route path="changes">
            <Route index element={<Guard capability="changes.read"><ChangeListPage /></Guard>} />
            <Route path=":id" element={<Guard capability="changes.read"><ChangeDetailPage /></Guard>} />
          </Route>

          <Route
            path="risks"
            element={<Guard capability="risks.read"><RiskListPage /></Guard>}
          />

          <Route path="inspections">
            <Route index element={<Guard capability="inspections.read"><InspectionListPage /></Guard>} />
            <Route path=":id" element={<Guard capability="inspections.read"><InspectionDetailPage /></Guard>} />
          </Route>

          <Route path="mgmt-reviews">
            <Route index element={<Guard capability="mgmt_reviews.read"><MgmtReviewListPage /></Guard>} />
            <Route path=":id" element={<Guard capability="mgmt_reviews.read"><MgmtReviewDetailPage /></Guard>} />
          </Route>

          <Route path="complaints">
            <Route index element={<Guard capability="complaints.read"><ComplaintListPage /></Guard>} />
            <Route path=":id" element={<Guard capability="complaints.read"><ComplaintDetailPage /></Guard>} />
          </Route>

          <Route path="admin">
            <Route path="users" element={<Guard capability="admin.users"><UsersPage /></Guard>} />
            <Route path="roles" element={<Guard capability="admin.roles"><RolesPage /></Guard>} />
            <Route path="audit-trail" element={<Guard capability="admin.users"><AuditTrailPage /></Guard>} />
          </Route>

          <Route path="*" element={<NotFoundPage />} />
        </Route>
      </Routes>
    </Suspense>
  );
}
