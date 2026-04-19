import SwiftUI
import MapKit

struct NearbyCoursesView: View {
    @Environment(ActiveRoundManager.self) private var roundManager
    @Environment(\.modelContext) private var context
    @Environment(\.dismiss) private var dismiss

    @State private var locationManager = LocationManager()
    @State private var searchManager   = CourseSearchManager()
    @State private var selectedCourse: NearbyCourse?
    @State private var showHoleEditor  = false
    @State private var pendingTemplate: CourseTemplate?
    @State private var radius: Double  = 25

    var onStartRound: ((CourseTemplate) -> Void)?

    var body: some View {
        NavigationStack {
            VStack(spacing: 0) {

                // Map
                if let region = searchManager.searchRegion {
                    Map(initialPosition: .region(region)) {
                        // User location pin
                        if let loc = locationManager.location {
                            Annotation("You", coordinate: loc.coordinate) {
                                Image(systemName: "location.fill")
                                    .foregroundStyle(.blue)
                                    .background(Circle().fill(.white).padding(-4))
                            }
                        }
                        // Course pins
                        ForEach(searchManager.results) { course in
                            Annotation(course.name, coordinate: course.coordinate) {
                                Button { selectedCourse = course } label: {
                                    Image(systemName: "flag.fill")
                                        .foregroundStyle(selectedCourse?.id == course.id ? .yellow : .green)
                                        .font(.title3)
                                        .background(Circle().fill(.white).padding(-4))
                                }
                                .buttonStyle(.plain)
                            }
                        }
                    }
                    .frame(height: 220)
                } else {
                    ZStack {
                        Rectangle().fill(Color.secondary.opacity(0.1))
                        VStack(spacing: 8) {
                            Image(systemName: "map").font(.largeTitle).foregroundStyle(.secondary)
                            Text("Map loads after search").foregroundStyle(.secondary).font(.callout)
                        }
                    }
                    .frame(height: 220)
                }

                Divider()

                // Radius picker + search button
                HStack(spacing: 12) {
                    Image(systemName: "location.circle").foregroundStyle(.green)
                    Picker("Radius", selection: $radius) {
                        Text("10 mi").tag(10.0)
                        Text("25 mi").tag(25.0)
                        Text("50 mi").tag(50.0)
                    }
                    .pickerStyle(.segmented)
                    Button {
                        Task { await runSearch() }
                    } label: {
                        if searchManager.isSearching {
                            ProgressView().controlSize(.small)
                        } else {
                            Label("Search", systemImage: "magnifyingglass")
                        }
                    }
                    .buttonStyle(.borderedProminent)
                    .tint(.green)
                    .disabled(searchManager.isSearching)
                }
                .padding(.horizontal).padding(.vertical, 10)

                Divider()

                // Results list
                Group {
                    if let error = searchManager.error {
                        ContentUnavailableView("Search Failed", systemImage: "wifi.slash",
                                               description: Text(error))
                    } else if searchManager.results.isEmpty && !searchManager.isSearching {
                        ContentUnavailableView(
                            locationManager.authStatus == .denied ? "Location Access Denied" : "No Courses Found",
                            systemImage: locationManager.authStatus == .denied ? "location.slash" : "flag",
                            description: Text(locationManager.authStatus == .denied
                                ? "Enable location access in Settings to find nearby courses."
                                : "Tap Search to find golf courses near you.")
                        )
                    } else {
                        List(searchManager.results) { course in
                            CourseResultRow(
                                course: course,
                                isSelected: selectedCourse?.id == course.id
                            ) {
                                selectedCourse = course
                            } onStartRound: {
                                pendingTemplate = course.toCustomCourse()
                                showHoleEditor  = true
                            }
                        }
                        .listStyle(.plain)
                    }
                }
            }
            .navigationTitle("Nearby Courses")
#if os(iOS)
            .navigationBarTitleDisplayMode(.inline)
#endif
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("Close") { dismiss() } }
            }
            .onAppear { requestLocationAndSearch() }
        }
        .sheet(isPresented: $showHoleEditor) {
            if let template = pendingTemplate {
                HoleEditorSheet(template: template) { finalTemplate in
                    onStartRound?(finalTemplate)
                    dismiss()
                }
            }
        }
    }

    // MARK: - Helpers

    private func requestLocationAndSearch() {
        locationManager.requestLocation()
        // Watch for location to arrive, then search
        Task {
            for _ in 0..<30 {            // wait up to 3 seconds
                try? await Task.sleep(for: .milliseconds(100))
                if locationManager.location != nil { break }
            }
            await runSearch()
        }
    }

    private func runSearch() async {
        guard let loc = locationManager.location else {
            locationManager.requestLocation()
            return
        }
        await searchManager.searchNearby(location: loc, radiusMiles: radius)
    }
}

// MARK: - Course Result Row

struct CourseResultRow: View {
    let course: NearbyCourse
    let isSelected: Bool
    let onSelect: () -> Void
    let onStartRound: () -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack(alignment: .top) {
                VStack(alignment: .leading, spacing: 3) {
                    Text(course.name)
                        .font(.headline)
                        .foregroundStyle(isSelected ? .green : .primary)
                    Text(course.address)
                        .font(.caption).foregroundStyle(.secondary)
                        .lineLimit(1)
                    HStack(spacing: 6) {
                        Image(systemName: "location.fill")
                            .font(.caption2).foregroundStyle(.green)
                        Text(course.distanceLabel)
                            .font(.caption).foregroundStyle(.secondary)
                        if let phone = course.phone {
                            Text("·").foregroundStyle(.secondary).font(.caption)
                            Text(phone).font(.caption).foregroundStyle(.secondary)
                        }
                    }
                }
                Spacer()
                Button(action: onStartRound) {
                    Label("Play", systemImage: "flag.fill")
                        .font(.callout.bold())
                        .padding(.horizontal, 12).padding(.vertical, 6)
                        .background(Color.green)
                        .foregroundStyle(.white)
                        .clipShape(Capsule())
                }
                .buttonStyle(.plain)
            }
        }
        .padding(.vertical, 6)
        .contentShape(Rectangle())
        .onTapGesture { onSelect() }
    }
}

// MARK: - Hole Editor Sheet

struct HoleEditorSheet: View {
    @State private var template: CourseTemplate
    @State private var rating: String
    @State private var slope: String
    @State private var holePars: [Int]
    let onConfirm: (CourseTemplate) -> Void
    @Environment(\.dismiss) private var dismiss

    init(template: CourseTemplate, onConfirm: @escaping (CourseTemplate) -> Void) {
        _template  = State(initialValue: template)
        _rating    = State(initialValue: String(format: "%.1f", template.rating))
        _slope     = State(initialValue: "\(template.slope)")
        _holePars  = State(initialValue: template.holes.map { $0.par })
        self.onConfirm = onConfirm
    }

    var body: some View {
        NavigationStack {
            Form {
                Section("Course Info") {
                    LabeledContent("Name", value: template.name)
                    LabeledContent("Location", value: template.location)
                    HStack {
                        Text("Course Rating")
                        Spacer()
                        TextField("72.0", text: $rating)
                            .multilineTextAlignment(.trailing).frame(width: 70)
#if os(iOS)
                            .keyboardType(.decimalPad)
#endif
                    }
                    HStack {
                        Text("Slope Rating")
                        Spacer()
                        TextField("113", text: $slope)
                            .multilineTextAlignment(.trailing).frame(width: 70)
#if os(iOS)
                            .keyboardType(.numberPad)
#endif
                    }
                }

                Section {
                    Text("Set the par for each hole. Edit after your round if needed.")
                        .font(.caption).foregroundStyle(.secondary)
                } header: {
                    Text("Hole Pars")
                }

                Section("Front 9") {
                    ForEach(0..<9, id: \.self) { i in HoleParRow(index: i, par: $holePars[i]) }
                }
                Section("Back 9") {
                    ForEach(9..<18, id: \.self) { i in HoleParRow(index: i, par: $holePars[i]) }
                }

                Section {
                    HStack {
                        Text("Total Par").bold()
                        Spacer()
                        Text("\(holePars.reduce(0, +))").bold()
                    }
                }
            }
            .navigationTitle("Set Up \(template.name)")
#if os(iOS)
            .navigationBarTitleDisplayMode(.inline)
#endif
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("Back") { dismiss() } }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Start Round") {
                        onConfirm(buildTemplate())
                    }
                    .fontWeight(.bold).foregroundStyle(.green)
                }
            }
        }
        .presentationDetents([.large])
    }

    private func buildTemplate() -> CourseTemplate {
        let holes = (0..<18).map { i in
            HoleInfo(number: i+1, par: holePars[i], yardage: 0, handicapIndex: i+1)
        }
        return CourseTemplate(
            name:     template.name,
            location: template.location,
            rating:   Double(rating) ?? 72.0,
            slope:    Int(slope) ?? 113,
            holes:    holes
        )
    }
}

struct HoleParRow: View {
    let index: Int
    @Binding var par: Int
    var body: some View {
        HStack {
            Text("Hole \(index + 1)").frame(width: 70, alignment: .leading)
            Spacer()
            Picker("Par", selection: $par) {
                Text("Par 3").tag(3)
                Text("Par 4").tag(4)
                Text("Par 5").tag(5)
            }
            .pickerStyle(.segmented).frame(width: 180)
        }
    }
}
