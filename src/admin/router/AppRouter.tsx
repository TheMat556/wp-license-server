import { Route, Routes } from 'react-router-dom';
import { LicensesPage } from '../pages/LicensesPage';

export function AppRouter() {
  return (
    <Routes>
      <Route path="*" element={<LicensesPage />} />
    </Routes>
  );
}
